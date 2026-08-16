<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

/*
 * A SyncSession lives for ONE connection, so its cached row id is null on
 * every reconnect. Inserting the session row unconditionally meant the second
 * connection to the same peer died on the (user, local, peer) unique index —
 * only reachable once handshakes started succeeding at all.
 */

function reconnectNoiseSession(string $peerSecret, string $peerPublic, string $localSecret, string $localPublic): NoiseSession
{
    $initiator = NoiseHandshakeState::initIkInitiator($peerSecret, $peerPublic, $localPublic);
    $responder = NoiseHandshakeState::initIkResponder($localSecret, $localPublic);

    $responder->readMessage($initiator->writeMessage(''));
    $initiator->readMessage($responder->writeMessage(''));

    [$send, $recv, $peerStatic] = $responder->split();

    return new NoiseSession($send, $recv, $peerStatic);
}

it('authenticating twice reuses the session row instead of colliding', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'reconnect-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $localKx = sodium_crypto_box_keypair();
    $localSecret = sodium_crypto_box_secretkey($localKx);
    $localPublic = sodium_crypto_box_publickey($localKx);

    $peerKx = sodium_crypto_box_keypair();
    $peerSecret = sodium_crypto_box_secretkey($peerKx);
    $peerPublic = sodium_crypto_box_publickey($peerKx);

    $now = '2026-06-14T00:00:00+00:00';
    $localDeviceId = 'local-'.bin2hex(random_bytes(4));
    $peerDeviceId = 'peer-'.bin2hex(random_bytes(4));

    foreach ([[$localDeviceId, $localPublic, 1], [$peerDeviceId, $peerPublic, 0]] as [$deviceId, $publicKey, $isSelf]) {
        $db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => 'Device',
            'ed25519_public_key_hex' => str_repeat('a', 64),
            'x25519_public_key_hex' => sodium_bin2hex($publicKey),
            'safety_number_words' => '',
            'is_self' => $isSelf,
            'paired_at' => $now,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $build = static fn (): SyncSession => new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: app(DeviceKeySigner::class),
        replayer: new OpLogReplayer(db: app(DatabaseManager::class), deviceKeys: [], rules: new MergeRulesRegistry),
        framer: new TransportFramer,
        db: app(DatabaseManager::class),
        clock: app(Clock::class),
    );

    $first = $build()->authenticate(
        reconnectNoiseSession($peerSecret, $peerPublic, $localSecret, $localPublic),
        $userId,
        $localDeviceId,
    );

    $second = $build()->authenticate(
        reconnectNoiseSession($peerSecret, $peerPublic, $localSecret, $localPublic),
        $userId,
        $localDeviceId,
    );

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        ->and($db->connection()->table('sync_sessions')->where('peer_device_id', $peerDeviceId)->count())->toBe(1);

    // A completed authentication is what "seen" means for the peer row.
    expect(
        $db->connection()->table('device_registry')->where('device_id', $peerDeviceId)->value('last_seen_at')
    )->not->toBeNull();
});
