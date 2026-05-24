<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Process\RunRegistry;

/*
 * Phase 16-04b Task 2 — DestructiveSpawnController invariants.
 *
 * The controller RE-VALIDATES all three gates server-side (T-16-02
 * defense-in-depth) even though TripleGateModal already validated:
 *   - DevModeFlag->isOn()
 *   - session('dev_mode.advanced') === true
 *   - request body confirmed_typed === 'beatrax' (hash_equals)
 *
 * On accept the controller routes through CommandSpawner::start(...,
 * 'destructive') and returns {run_id, pid} with HTTP 202 — same shape
 * as the SAFE controller so the runner UI consumes both pathways
 * identically.
 *
 * Skipped on systems lacking the posix extension (Linux/macOS only).
 */

beforeEach(function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required');
    }
});

function destructiveUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function setDevModeFlagForDestructive(bool $on): void
{
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('app.dev_mode', $on);
}

it('rejects POST /dev/artisan/destructive-spawn with 403 when Dev Mode env is off', function (): void {
    $user = destructiveUser('dest-env-off');
    setDevModeFlagForDestructive(false);

    $response = $this->actingAs($user)
        ->withSession(['dev_mode.advanced' => true])
        ->postJson('/dev/artisan/destructive-spawn', [
            'command' => 'db:restore',
            'args' => ['from' => '/tmp/x.sqlite'],
            'confirmed_typed' => 'beatrax',
        ]);

    $response->assertStatus(403);
});

it('rejects POST /dev/artisan/destructive-spawn with 403 when session.advanced is not true', function (): void {
    $user = destructiveUser('dest-advanced-off');
    setDevModeFlagForDestructive(true);

    $response = $this->actingAs($user)
        // Session lacks dev_mode.advanced
        ->postJson('/dev/artisan/destructive-spawn', [
            'command' => 'db:restore',
            'args' => ['from' => '/tmp/x.sqlite'],
            'confirmed_typed' => 'beatrax',
        ]);

    $response->assertStatus(403);
});

it('rejects POST /dev/artisan/destructive-spawn with 403 when confirmed_typed is wrong case', function (): void {
    $user = destructiveUser('dest-typo');
    setDevModeFlagForDestructive(true);

    $response = $this->actingAs($user)
        ->withSession(['dev_mode.advanced' => true])
        ->postJson('/dev/artisan/destructive-spawn', [
            'command' => 'db:restore',
            'args' => ['from' => '/tmp/x.sqlite'],
            'confirmed_typed' => 'Beatrax',
        ]);

    $response->assertStatus(403);
});

it('rejects a SAFE-tier command name with 422 not_destructive even when all three gates pass', function (): void {
    $user = destructiveUser('dest-safe-cmd');
    setDevModeFlagForDestructive(true);

    $response = $this->actingAs($user)
        ->withSession(['dev_mode.advanced' => true])
        ->postJson('/dev/artisan/destructive-spawn', [
            'command' => 'cache:clear', // SAFE tier
            'args' => [],
            'confirmed_typed' => 'beatrax',
        ]);

    $response->assertStatus(422);
    $response->assertJson(['error' => 'not_destructive', 'command' => 'cache:clear']);
});

it('spawns a destructive command + returns 202 + run_id + pid when all three gates pass', function (): void {
    $user = destructiveUser('dest-happy');
    setDevModeFlagForDestructive(true);

    $response = $this->actingAs($user)
        ->withSession(['dev_mode.advanced' => true])
        ->postJson('/dev/artisan/destructive-spawn', [
            'command' => 'migrate:fresh', // DESTRUCTIVE tier
            'args' => [],
            'confirmed_typed' => 'beatrax',
        ]);

    $response->assertStatus(202);
    $response->assertJsonStructure(['run_id', 'pid']);
    $runId = $response->json('run_id');
    expect($runId)->toBeString();
    expect($response->json('pid'))->toBeGreaterThan(0);

    // The cached run record should reflect tier='destructive' so the
    // audit pipeline can read it back when ArtisanStreamController
    // finalizes the run.
    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $record = $registry->find($runId);
    expect($record)->not->toBeNull();
    expect($record->tier)->toBe('destructive');
    expect($record->command)->toBe('migrate:fresh');
});
