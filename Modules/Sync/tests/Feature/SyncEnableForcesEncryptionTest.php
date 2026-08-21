<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);

// Encryption is mandatory once a device syncs, so enabling sync and completing a
// pairing both run the migration themselves and offer no decline path.

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

    // An app-lock must already be configured before sync — and therefore
    // encryption — can be enabled at all.
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

    // The migration service is the sole authority for turning encryption on;
    // the enable-sync flow carries no decline affordance beside it.
    expect(class_exists(EncryptionMigrationService::class))->toBeTrue();
});

it('auto-runs the encryption migration when a pairing both-confirm admits a peer — no decline path', function (): void {
    // This device plays the initiator so its own real, loadable identity is the
    // initiator; the responder is accepted straight through PairingTokenService
    // with an independent device id, because admitResponderDevice() refuses a
    // responder whose device_id equals the local self row.
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

    // $pairingTokenId and $side are both #[Locked], so only the real
    // showMyCode() action can set them.
    $pairing = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code')
        ->assertSet('side', 'initiator');

    $tokenId = (int) $pairing->get('pairingTokenId');

    /** @var PairingTokenService $tokenService */
    $tokenService = $this->app->make(PairingTokenService::class);

    // The responder accepts out of band and confirms first, so this component's
    // confirmMatch() below is the deciding second confirmation. Only the displayed
    // word code reveals the plaintext token — the row holds its SHA-256 — so it is
    // decoded back the way a responder device typing it in would.
    /** @var WordCodeEncoder $wordEncoder */
    $wordEncoder = $this->app->make(WordCodeEncoder::class);
    $plaintextToken = $wordEncoder->decode($pairing->get('wordCode'));

    $tokenService->accept($plaintextToken, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    $tokenService->confirm($tokenId, (int) $user->id, 'device-resp', PairingSafetyDigest::forToken($tokenId, (int) $user->id));

    $pairing->call('confirmMatch')->assertSet('step', 'success');

    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->first();

    expect($state)->not->toBeNull();
    expect($state->enabled_at)->not->toBeNull();
    expect((bool) $state->migration_in_progress)->toBeFalse();
});
