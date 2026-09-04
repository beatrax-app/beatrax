<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Support\DevModeSession;

// EnsureDeveloperMode sits on the /dev routes, and route middleware does not
// re-run on a Livewire update: the endpoint verifies the snapshot and calls the
// method. Once the developer flag came off, GET /dev/sql answered 404 while the
// SQL panel behind it still rendered the whole schema and still ran the
// statement box, from a snapshot the browser was already holding.

function devGateSnapshot(string $pageHtml, string $component): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"'.$component.'"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for '.$component.' on the rendered page.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function devGateRun(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['path' => '', 'method' => 'run', 'params' => []]],
        ]],
    ]);
}

function devGateUser(): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'dev-gate-wire',
        'password' => 'fixture',
        'period_start_day' => 1,
        'is_developer' => true,
    ]);

    return $user;
}

it('refuses the SQL panel over the wire once the developer flag is off', function (): void {
    $user = devGateUser();
    $this->actingAs($user)->withSession([DevModeSession::ADVANCED_KEY => true]);

    $snapshot = devGateSnapshot($this->get('/dev/sql')->assertOk()->getContent(), 'dev.sql-panel-page');

    app(DatabaseManager::class)->connection()->table('users')
        ->where('id', $user->id)
        ->update(['is_developer' => false]);

    // Re-bound so the guard resolves the row as it now stands: a real request
    // reads the user back out of the session store on every hit.
    $this->actingAs($user->fresh());

    $this->get('/dev/sql')->assertNotFound();

    $response = devGateRun($snapshot, ['sqlInput' => 'select username from users']);

    $response->assertNotFound();

    // The status is only half of it: the panel re-renders its schema sidebar on
    // every update, so a 200 here handed over the table list as well.
    expect($response->getContent())->not->toContain('dev-gate-wire');
});

it('leaves a developer driving the same panel', function (): void {
    $user = devGateUser();
    $this->actingAs($user)->withSession([DevModeSession::ADVANCED_KEY => true]);

    $snapshot = devGateSnapshot($this->get('/dev/sql')->assertOk()->getContent(), 'dev.sql-panel-page');

    $response = devGateRun($snapshot, ['sqlInput' => 'select username from users']);

    $response->assertOk();
    expect($response->getContent())->toContain('dev-gate-wire');
});
