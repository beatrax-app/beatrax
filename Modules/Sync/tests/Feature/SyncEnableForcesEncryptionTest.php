<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

/*
 * SyncEnableForcesEncryptionTest — D-07: enabling sync / confirming pairing
 * on a not-yet-encrypted device AUTO-runs the migration and leaves
 * encryption ON with NO decline path (mandatory-when-synced).
 * 14-VALIDATION.md D-07 row.
 *
 * RED until Plan 09 wires DevicesAndSyncSettingsSection::enableSync() (and
 * the pairing-confirm flow) to auto-invoke
 * Modules\Core\Public\Services\EncryptionMigrationService::migrate() with no
 * decline affordance. This test references the planned production FQCN,
 * which does not yet exist — the failure is "class not found", the correct
 * Wave 0 RED state.
 */

function syncEnableForcesEncryptionUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('auto-runs the encryption migration and turns encryption ON when enableSync succeeds — no decline path', function (): void {
    $user = syncEnableForcesEncryptionUser('sync-forces-encryption-user');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Precondition per the D-02 gate: an app-lock must already be configured
    // before sync (and therefore encryption) can be enabled.
    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('enableSync')
        ->assertSet('appLockConfigured', true);

    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->first();

    expect($state)->not->toBeNull();
    expect($state->enabled_at)->not->toBeNull();
    expect((bool) $state->migration_in_progress)->toBeFalse();

    // Sanity: the planned migration service exists and is the sole authority
    // for turning encryption on — no separate "decline" affordance exists in
    // the enable-sync flow for a synced device (D-07 mandatory-when-synced).
    expect(class_exists(EncryptionMigrationService::class))->toBeTrue();
});
