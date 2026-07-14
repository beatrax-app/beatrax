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

/*
 * PairingGatewayImportSeamTest — Task 1 (Phase 15 import-join): the narrow
 * Public seams the mobile fresh-device import bootstrap drives, added
 * WITHOUT touching any existing PairingGateway method's behavior.
 *
 * enableSyncIdentityWithoutEpoch() is the B2 fix's foundation: it creates
 * the device's sync identity (self device_registry row + encrypted
 * identity key-file) WITHOUT self-minting a GDK epoch, so the phone's
 * keyring stays genuinely empty until the desktop's real epochs are
 * delivered post-pairing.
 */

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

    // B2: no sync_encryption_state row (no epoch minted) — the keyring stays
    // empty so a subsequently-delivered desktop epoch 1 does not collide
    // with GdkEpochControlHandler's idempotency guard.
    $encryptionState = $db->connection()->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->first();

    expect($encryptionState)->toBeNull('enableSyncIdentityWithoutEpoch must never create a sync_encryption_state row');

    // The device identity is usable — currentDeviceId() resolves it (the
    // EXISTING PairingGateway::currentDeviceId() method, unchanged).
    expect($gateway->currentDeviceId((int) $user->id, $session))->toBe($selfRow->device_id);
});

it('does not create the on-disk GDK keyring file (B2)', function (): void {
    $user = importSeamUser('import-seam-keyring-file');

    // A prior, unrelated test process may have left a keyring file on disk
    // for this same numeric user id (SQLite rowids can be reused across
    // RefreshDatabase transaction rollbacks — GdkEpochDeliveryTest's own
    // documented cross-test filesystem-isolation gap). Start from a known
    // state so this assertion is not a false failure against stale fixture
    // data encrypted under a different KEK.
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
