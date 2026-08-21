<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Exceptions\LanSyncException;
use Modules\Sync\Public\Services\DeviceRegistryService;

// Removing a device on the desktop drops it from that side's registry, but
// nothing travelled back, so the removed phone kept listing the peer and calling
// itself connected and synced. The notice now rides the completed Noise session,
// which is what proves the responder holds the static key the phone dialled.

it('marks only a peer revocation as unretryable', function (): void {
    $revoked = LanSyncException::peerRevokedThisDevice();
    $ordinary = LanSyncException::peerFailedConfirmedDeviceGate();
    $disconnect = LanSyncException::peerDisconnectedBeforeHandshakeMessage('msg2');

    // Retrying a revocation is pointless; retrying the others is not.
    expect($revoked->isPeerRevocation())->toBeTrue()
        ->and($ordinary->isPeerRevocation())->toBeFalse()
        ->and($disconnect->isPeerRevocation())->toBeFalse();
});

it('stops treating the peer as confirmed once its confirmation is cleared', function (): void {
    $db = app(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'revoked-claims-sync',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userId = (int) $user->id;

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'desktop-peer',
        'name' => "Wessel's Mac",
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:05:00Z',
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:00:00Z',
    ]);

    /** @var DeviceRegistryService $devices */
    $devices = app(DeviceRegistryService::class);

    expect($devices->otherDeviceNames($userId))->toHaveCount(1);

    // What forgetRevokedPeer() does when PEER_REVOKED arrives.
    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('is_self', 0)
        ->update(['confirmed_at' => null]);

    // Every surface that asks "do I have a peer?" must now say no — that is
    // what the sync screen renders its connected state from.
    expect($devices->otherDeviceNames($userId))->toBe([])
        ->and($devices->isStillConfirmed($userId, 'desktop-peer'))->toBeFalse();
});

it('sends the revocation notice before hanging up on an unconfirmed peer', function (): void {
    $source = (string) file_get_contents(base_path('Modules/Sync/Internal/Transport/SyncWebSocketHandler.php'));

    $tell = strpos($source, '$this->tellPeerItIsRevoked($client, $noiseSession);');
    $close = strpos($source, '$client->close();', (int) $tell);

    // Closing first would leave the peer unable to tell removal from a flaky
    // link, which is the state this whole fix exists to end.
    expect($tell)->toBeInt()
        ->and($close)->toBeInt()
        ->and($tell)->toBeLessThan($close);
});
