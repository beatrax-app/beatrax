<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\DeviceRegistryService;

// SyncWebSocketHandler read the confirmed-device key map once per connection, so
// a peer removed while connected kept syncing until it happened to reconnect.
// The live loop re-asks now, which only helps while the answer comes from the
// database rather than from that same connect-time snapshot.

function revokedPeerUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function revokedPeerDevice(DatabaseManager $db, int $userId, string $deviceId, bool $confirmed): int
{
    return (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Peer '.$deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => $confirmed ? '2026-08-01T10:05:00Z' : null,
        'last_seen_at' => null,
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:00:00Z',
    ]);
}

it('reports a confirmed peer as still trusted', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) revokedPeerUser('revoked-peer-confirmed')->id;
    revokedPeerDevice($db, $userId, 'peer-alive', confirmed: true);

    expect(app(DeviceRegistryService::class)->isStillConfirmed($userId, 'peer-alive'))->toBeTrue();
});

it('stops trusting a peer the moment its confirmation is revoked', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) revokedPeerUser('revoked-peer-revoked')->id;
    revokedPeerDevice($db, $userId, 'peer-revoked', confirmed: true);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    expect($registry->isStillConfirmed($userId, 'peer-revoked'))->toBeTrue();

    // Exactly what revocation does before purge() removes the row.
    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', 'peer-revoked')
        ->update(['confirmed_at' => null]);

    expect($registry->isStillConfirmed($userId, 'peer-revoked'))
        ->toBeFalse('an open session must see the revocation, not its connect-time snapshot');
});

it('stops trusting a peer whose row has been purged entirely', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) revokedPeerUser('revoked-peer-purged')->id;
    $rowId = revokedPeerDevice($db, $userId, 'peer-purged', confirmed: true);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    $registry->purge($userId, $rowId);

    expect($registry->isStillConfirmed($userId, 'peer-purged'))->toBeFalse();
});

it('never treats another user device or an empty id as trusted', function (): void {
    $db = app(DatabaseManager::class);
    $ownerId = (int) revokedPeerUser('revoked-peer-owner')->id;
    $strangerId = (int) revokedPeerUser('revoked-peer-stranger')->id;
    revokedPeerDevice($db, $ownerId, 'peer-of-owner', confirmed: true);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    expect($registry->isStillConfirmed($strangerId, 'peer-of-owner'))->toBeFalse()
        ->and($registry->isStillConfirmed($ownerId, ''))->toBeFalse();
});

it('checks revocation inside the live loop, before the ops it would apply', function (): void {
    $source = (string) file_get_contents(base_path('Modules/Sync/Internal/Transport/SyncWebSocketHandler.php'));

    $check = strpos($source, '$this->peerWasRevoked($session)');
    $apply = strpos($source, '$session->receiveOps($ciphertext, $this->userId, $deviceKeys);');

    expect($check)->toBeInt('the live loop must re-check trust')
        ->and($apply)->toBeInt()
        ->and($check)->toBeLessThan($apply, 'a revoked peer must not have its ops applied');
});
