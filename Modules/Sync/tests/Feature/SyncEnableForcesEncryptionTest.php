<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;

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

it('auto-runs the encryption migration when a pairing both-confirm admits a peer — no decline path (D-07)', function (): void {
    // This device already ran enableSync() at some earlier point (its own
    // identity key-file + self device_registry row already exist) but
    // encryption is NOT yet on — mirrors a pre-Phase-14 device, or one where
    // an earlier auto-migrate attempt never ran. It now completes pairing a
    // NEW peer device: this is the second, independent D-07 trigger point
    // ("the responder becomes a peer here") beyond enableSync() itself.
    //
    // This device plays the INITIATOR side (showMyCode) so its own real,
    // loadable identity is used as the initiator_device_id — the fake
    // "responder" is accepted directly via PairingTokenService::accept()
    // with an independent device id (mirrors PairingFlowTest.php's own
    // service-level fixture idiom), which keeps this test clear of the
    // unrelated WR-05 self-collision guard (admitResponderDevice() refuses
    // to admit a responder whose device_id equals the LOCAL self-row — that
    // guard would fire spuriously if the "responder" reused this device's
    // own identity, since only one identity file per user exists in a
    // single test process).
    $user = syncEnableForcesEncryptionUser('pairing-forces-encryption-user');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())->toBeFalse();

    // This device shows its own code — sets $pairingTokenId/$side inside the
    // component via the real showMyCode() action (both are #[Locked] and
    // cannot be set directly in a test).
    $pairing = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code')
        ->assertSet('side', 'initiator');

    $tokenId = (int) $pairing->get('pairingTokenId');

    /** @var PairingTokenService $tokenService */
    $tokenService = $this->app->make(PairingTokenService::class);

    // An independent responder device accepts the code out of band, then
    // confirms first — bothConfirmed() is not yet true, so THIS component's
    // own confirmMatch() call below is the second/deciding confirmation. The
    // plaintext token is only ever known via the component's own displayed
    // $wordCode (the DB stores only its SHA-256 hash) — decode that back to
    // the plaintext hex the same way a real responder device would type it in.
    /** @var WordCodeEncoder $wordEncoder */
    $wordEncoder = $this->app->make(WordCodeEncoder::class);
    $plaintextToken = $wordEncoder->decode($pairing->get('wordCode'));

    $tokenService->accept($plaintextToken, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    $tokenService->confirm($tokenId, (int) $user->id, 'device-resp');

    $pairing->call('confirmMatch')->assertSet('step', 'success');

    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->first();

    expect($state)->not->toBeNull();
    expect($state->enabled_at)->not->toBeNull();
    expect((bool) $state->migration_in_progress)->toBeFalse();
});
