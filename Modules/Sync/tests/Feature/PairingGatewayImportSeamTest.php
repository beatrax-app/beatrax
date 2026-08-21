<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// A joining device gets its sync identity without minting an epoch of its own,
// so its keyring stays genuinely empty until the peer's real epochs arrive. A
// self-minted epoch 1 collides with the delivered one and strands the imported
// history in quarantine permanently.

function importSeamUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('enableSyncIdentityWithoutEpoch creates a self device_registry row and does NOT mint a GDK epoch (B2)', function (): void {
    $user = importSeamUser('import-seam-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->enableSyncIdentityWithoutEpoch((int) $user->id, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $selfRow = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 1)
        ->first();

    expect($selfRow)->not->toBeNull('a self device_registry row must exist after identity bootstrap');
    expect($selfRow->confirmed_at)->not->toBeNull();

    // No epoch row at all, so a delivered epoch 1 cannot collide with a
    // self-minted one and be dropped by the idempotency guard.
    $encryptionState = $db->connection()->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->first();

    expect($encryptionState)->toBeNull('enableSyncIdentityWithoutEpoch must never create a sync_encryption_state row');

    expect($gateway->currentDeviceId((int) $user->id, $session))->toBe($selfRow->device_id);
});

it('does not create the on-disk GDK keyring file (B2)', function (): void {
    $user = importSeamUser('import-seam-keyring-file');

    // SQLite rowids are reused across the per-test rollback, so an earlier test
    // can have left a keyring on disk under this same numeric user id — one
    // encrypted under a different key, which would fail this assertion falsely.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->enableSyncIdentityWithoutEpoch((int) $user->id, $session);

    expect(file_exists(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc')))->toBeFalse(
        'enableSyncIdentityWithoutEpoch must never write a GDK keyring file'
    );

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->epochs())->toBe([], 'the GDK keyring must be genuinely empty after identity-only bootstrap');
});
