<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;

// Skipping a version filters later update.available rows for that version out
// of the banner. It must never filter update.stale or update.critical: those
// exist precisely to reach a user who has been dismissing the ordinary notice,
// so they bypass the skip list on purpose.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'skip-user',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function skipInsertAlert(DatabaseManager $db, array $overrides = []): int
{
    $defaults = [
        'user_id' => null,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-28 00:00:00',
        'acknowledged_at' => null,
    ];

    return $db->connection()->table('system_alerts')->insertGetId(array_merge($defaults, $overrides));
}

it('adds the skipped_update_versions JSON column to the user_preferences table', function (): void {
    expect(Schema::hasColumn('user_preferences', 'skipped_update_versions'))->toBeTrue();
});

it('writes the dismissed version into user_preferences.skipped_update_versions AND acknowledges the alert', function (): void {
    $id = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'Update available — Beatrax 0.1.1 is ready. It will install on next launch.',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->call('skipVersion', $id);

    /** @var UserPreference $pref */
    $pref = UserPreference::query()->where('user_id', $this->user->id)->firstOrFail();
    expect($pref->skipped_update_versions)->toBe(['0.1.1']);

    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row?->acknowledged_at)->not->toBeNull();
});

it('does not duplicate the version in the skipped list when skipVersion is called twice for the same version', function (): void {
    $firstAlertId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'first',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
    ]);
    $secondAlertId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'second',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
        'created_at' => '2026-05-28 01:00:00',
    ]);

    $component = Livewire::actingAs($this->user)->test(SystemAlertsBanner::class);
    $component->call('skipVersion', $firstAlertId);
    $component->call('skipVersion', $secondAlertId);

    /** @var UserPreference $pref */
    $pref = UserPreference::query()->where('user_id', $this->user->id)->firstOrFail();
    expect($pref->skipped_update_versions)->toBe(['0.1.1']);
});

it('suppresses a subsequent update.available row for an already-skipped version', function (): void {
    $firstId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'Update available — Beatrax 0.1.1 is ready. It will install on next launch.',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)->call('skipVersion', $firstId);

    // Un-acknowledged, so the banner would render this one on its own; the skip
    // list is what has to hold it back.
    $secondId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'Update available — Beatrax 0.1.1 is ready. It will install on next launch.',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
        'created_at' => '2026-05-28 02:00:00',
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertDontSeeHtml('data-testid="resolve-alert-'.$secondId.'"');
});

it('still renders update.available for a different version after skipping one version', function (): void {
    $oldId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'fixture',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)->call('skipVersion', $oldId);

    $newId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'Update available — Beatrax 0.1.2 is ready. It will install on next launch.',
        'metadata' => json_encode(['latestVersion' => '0.1.2']),
        'created_at' => '2026-05-28 03:00:00',
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('Beatrax 0.1.2 is ready')
        ->assertSeeHtml('data-testid="resolve-alert-'.$newId.'"');
});

it('does not suppress update.stale or update.critical rows even when their latestVersion is in the skip list', function (): void {
    $availableId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'fixture',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)->call('skipVersion', $availableId);

    $staleId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.stale',
        'severity' => 'warning',
        'message' => "You're on version 0.1.0 — version 0.1.1 has been available for 30 days. Update now.",
        'metadata' => json_encode(['currentVersion' => '0.1.0', 'latestVersion' => '0.1.1']),
        'created_at' => '2026-05-28 04:00:00',
    ]);
    $criticalId = skipInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.critical',
        'severity' => 'critical',
        'message' => 'Critical update available — version 0.1.1 fixes data loss. Install as soon as possible.',
        'metadata' => json_encode(['newVersion' => '0.1.1']),
        'created_at' => '2026-05-28 05:00:00',
    ]);

    $component = Livewire::actingAs($this->user)->test(SystemAlertsBanner::class);
    $component->assertSeeHtml('data-testid="resolve-alert-'.$staleId.'"');
    $component->assertSeeHtml('data-testid="resolve-alert-'.$criticalId.'"');
});
