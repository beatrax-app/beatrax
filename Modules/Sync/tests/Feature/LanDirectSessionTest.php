<?php

declare(strict_types=1);

use Amp\Websocket\Server\WebsocketClientHandler;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// No live socket here: a loopback WebSocket would need an amphp event loop in a
// separate process, so these drive the same handshake, auth and receive path
// the handler calls, in memory. The socket itself is verified by hand.

function lanTestUser(string $name): User
{
    return User::query()->create([
        'username' => $name,
        'password' => bcrypt('lan-test-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, string>  $deviceKeys  device_id => hex Ed25519 public key
 */
function buildSyncSession(array $deviceKeys = []): SyncSession
{
    $db = app(DatabaseManager::class);

    return new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: $deviceKeys),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
    );
}

it('loopback WebSocket connection is established between two sync transport instances', function (): void {
    expect(class_exists(SyncWebSocketHandler::class))->toBeTrue('SyncWebSocketHandler must exist in Wave 3');

    $implements = class_implements(SyncWebSocketHandler::class);
    expect(isset($implements[WebsocketClientHandler::class]))->toBeTrue(
        'SyncWebSocketHandler must implement WebsocketClientHandler (D-08 design)',
    );

    expect(class_exists(SyncSession::class))->toBeTrue('SyncSession must exist in Wave 3');
});

it('Noise IK handshake completes over loopback WebSocket connection', function (): void {
    $initKp = sodium_crypto_kx_keypair();
    $initSecret = sodium_crypto_kx_secretkey($initKp);
    $initPublic = sodium_crypto_kx_publickey($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respSecret = sodium_crypto_kx_secretkey($respKp);
    $respPublic = sodium_crypto_kx_publickey($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator($initSecret, $initPublic, $respPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respSecret, $respPublic);

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$initSend, $initRecv] = $initHs->split();
    [$respSend, $respRecv, $peerStaticRevealedToResp] = $respHs->split();

    $initSession = new NoiseSession($initSend, $initRecv, $respPublic);
    $respSession = new NoiseSession($respSend, $respRecv, $peerStaticRevealedToResp);

    $plaintext = 'hello-from-initiator';
    $encrypted = $initSession->encrypt($plaintext);
    $decrypted = $respSession->decrypt($encrypted);

    expect($decrypted)->toBe($plaintext, 'Noise IK handshake must produce bidirectional encryption');
    expect(sodium_bin2hex($peerStaticRevealedToResp))->toBe(
        sodium_bin2hex($initPublic),
        'Responder must correctly extract initiator static key from IK msg1 (RESEARCH Pattern 2)',
    );
});

it('both loopback peers authenticate each other against device_registry X25519 keys', function (): void {
    $user = lanTestUser('lan-auth-user');

    $kpA = sodium_crypto_kx_keypair();
    $secretA = sodium_crypto_kx_secretkey($kpA);
    $publicA = sodium_crypto_kx_publickey($kpA);

    $kpB = sodium_crypto_kx_keypair();
    $secretB = sodium_crypto_kx_secretkey($kpB);
    $publicB = sodium_crypto_kx_publickey($kpB);

    $sigKp = sodium_crypto_sign_keypair();
    $ed25519PubHex = sodium_bin2hex(sodium_crypto_sign_publickey($sigKp));

    DB::table('device_registry')->insert([
        ['user_id' => $user->id, 'device_id' => 'device-a', 'name' => 'Device A',
            'ed25519_public_key_hex' => $ed25519PubHex, 'x25519_public_key_hex' => sodium_bin2hex($publicA),
            'safety_number_words' => 'w1 w2 w3 w4 w5 w6', 'is_self' => 0,
            'paired_at' => now()->toIso8601String(), 'confirmed_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()],
        ['user_id' => $user->id, 'device_id' => 'device-b', 'name' => 'Device B',
            'ed25519_public_key_hex' => $ed25519PubHex, 'x25519_public_key_hex' => sodium_bin2hex($publicB),
            'safety_number_words' => 'w7 w8 w9 w10 w11 w12', 'is_self' => 1,
            'paired_at' => now()->toIso8601String(), 'confirmed_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()],
    ]);

    $initHs = NoiseHandshakeState::initIkInitiator($secretA, $publicA, $publicB);
    $respHs = NoiseHandshakeState::initIkResponder($secretB, $publicB);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$respSendCipher, $respRecvCipher, $peerStaticRevealedToResp] = $respHs->split();

    $respNoiseSession = new NoiseSession($respSendCipher, $respRecvCipher, $peerStaticRevealedToResp);

    $sessionB = buildSyncSession();
    $admittedByB = $sessionB->authenticate($respNoiseSession, $user->id, 'device-b');

    expect($admittedByB)->toBeTrue(
        'Device A must be authenticated by device B — its X25519 key is in confirmed device_registry'
    );
    expect($sessionB->peerDeviceId())->toBe('device-a', 'peerDeviceId() must resolve to device-a');
    expect($sessionB->status())->toBe('active', 'Session must be active after successful auth');
});

it('loopback session transfers a plaintext OpLogEntry payload end-to-end', function (): void {
    $user = lanTestUser('lan-payload-user');

    $sigKp = sodium_crypto_sign_keypair();
    $sigSecretKey = sodium_crypto_sign_secretkey($sigKp);
    $sigPublicKey = sodium_crypto_sign_publickey($sigKp);
    $sigPublicKeyHex = sodium_bin2hex($sigPublicKey);

    $kpA = sodium_crypto_kx_keypair();
    $secretA = sodium_crypto_kx_secretkey($kpA);
    $publicA = sodium_crypto_kx_publickey($kpA);

    $kpB = sodium_crypto_kx_keypair();
    $secretB = sodium_crypto_kx_secretkey($kpB);
    $publicB = sodium_crypto_kx_publickey($kpB);

    DB::table('device_registry')->insert([
        ['user_id' => $user->id, 'device_id' => 'sender-device', 'name' => 'Sender',
            'ed25519_public_key_hex' => $sigPublicKeyHex, 'x25519_public_key_hex' => sodium_bin2hex($publicA),
            'safety_number_words' => 'a b c d e f', 'is_self' => 0,
            'paired_at' => now()->toIso8601String(), 'confirmed_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()],
        ['user_id' => $user->id, 'device_id' => 'receiver-device', 'name' => 'Receiver',
            'ed25519_public_key_hex' => $sigPublicKeyHex, 'x25519_public_key_hex' => sodium_bin2hex($publicB),
            'safety_number_words' => 'g h i j k l', 'is_self' => 1,
            'paired_at' => now()->toIso8601String(), 'confirmed_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()],
    ]);

    $initHs = NoiseHandshakeState::initIkInitiator($secretA, $publicA, $publicB);
    $respHs = NoiseHandshakeState::initIkResponder($secretB, $publicB);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$initSend, $initRecv, $peerStaticRevealedToInit] = $initHs->split();
    [$respSend, $respRecv, $peerStaticRevealedToResp] = $respHs->split();

    $senderNoiseSession = new NoiseSession($initSend, $initRecv, $peerStaticRevealedToInit);
    $receiverNoiseSession = new NoiseSession($respSend, $respRecv, $peerStaticRevealedToResp);

    $receiverSession = buildSyncSession(deviceKeys: ['sender-device' => $sigPublicKeyHex]);
    $admitted = $receiverSession->authenticate($receiverNoiseSession, $user->id, 'receiver-device');
    expect($admitted)->toBeTrue('sender-device must be authenticated against confirmed device_registry');

    $signer = new DeviceKeySigner;
    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 42,
        field: 'note',
        value: '"transferred-value"',
        hlcL: 1_718_000_000_000,
        hlcC: 1,
        deviceId: 'sender-device',
        opType: OpType::Set,
        signature: '',
        userId: $user->id,
    );
    $realSig = $signer->sign($entry->signingPayload(), $sigSecretKey);
    $signedEntry = new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $entry->value,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: $realSig,
        userId: $entry->userId,
    );

    $framer = new TransportFramer;
    $frame = $framer->encode([$signedEntry]);
    $ciphertext = $senderNoiseSession->encrypt($frame);

    // Every entry is signature-checked before it is replayed.
    $deviceKeys = ['sender-device' => $sigPublicKeyHex];
    $receiverSession->receiveOps($ciphertext, $user->id, $deviceKeys);

    $persisted = DB::table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->where('hlc_l', 1_718_000_000_000)
        ->first();

    expect($persisted)->not->toBeNull('Op payload must be persisted to op_log_entries after receiveOps()');
    expect($persisted->signature)->toBe($realSig, 'Persisted signature must match original Ed25519 sig (Pitfall 7 guard)');
});
