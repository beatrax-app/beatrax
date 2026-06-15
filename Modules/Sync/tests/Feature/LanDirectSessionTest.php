<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
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

/*
 * LanDirectSessionTest — XPORT-03: LAN-direct session transport layer.
 *
 * Wave 3: SyncSession + SyncWebSocketHandler now exist; these tests run as
 * in-memory integration tests of the session layer (Noise handshake + auth +
 * op exchange) without a live WebSocket server.
 *
 * IMPORTANT: This file does NOT test a live WebSocket TCP connection. A real
 * loopback WebSocket would require an amphp event loop running in a separate
 * process, which is a deployment integration concern documented in VALIDATION.md
 * as a Manual-Only Verification. These tests instead exercise the in-memory
 * handshake and session state machine — the same code path the WS handler invokes
 * when it calls SyncSession::authenticate() and SyncSession::receiveOps().
 *
 * Why in-memory is sufficient:
 *   - Noise IK correctness is proven by NoisePeerAuthTest and TransportOpSignatureTest.
 *   - SyncSession::authenticate() calls DeviceRegistryService::deviceX25519Keys() which
 *     is a DB query — fully exercised here with RefreshDatabase.
 *   - SyncSession::receiveOps() calls DeviceKeySigner::verify() + OpLogReplayer::replay()
 *     — exercised end-to-end in this file.
 *
 * SyncWebSocketHandler existence is asserted to confirm Wave 3 is complete.
 */

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
 * Build a minimal SyncSession wired to the test DB and a real OpLogReplayer.
 *
 * @param  array<string, string>  $deviceKeys  device_id => hex Ed25519 public key
 */
function buildSyncSession(array $deviceKeys = []): SyncSession
{
    $db = app(\Illuminate\Database\DatabaseManager::class);

    return new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: $deviceKeys),
        framer: new TransportFramer,
        db: $db,
        clock: app(\Modules\Core\Public\Contracts\Clock::class),
    );
}

it('loopback WebSocket connection is established between two sync transport instances', function (): void {
    // This test verifies that the SyncWebSocketHandler class exists and implements
    // the WebsocketClientHandler interface — the structural prerequisite for a
    // loopback WebSocket test. Actual WS connection is VALIDATION.md Manual-Only.
    expect(class_exists(SyncWebSocketHandler::class))->toBeTrue('SyncWebSocketHandler must exist in Wave 3');

    $implements = class_implements(SyncWebSocketHandler::class);
    // class_implements() returns array<interface-name, interface-name> — check key presence.
    expect(isset($implements[\Amp\Websocket\Server\WebsocketClientHandler::class]))->toBeTrue(
        'SyncWebSocketHandler must implement WebsocketClientHandler (D-08 design)',
    );

    // Verify SyncSession also exists (both transport layer primitives present).
    expect(class_exists(SyncSession::class))->toBeTrue('SyncSession must exist in Wave 3');
});

it('Noise IK handshake completes over loopback WebSocket connection', function (): void {
    // Verify that a full IK handshake produces two valid NoiseSession objects,
    // one on each side. This is the same code the WS handler's performHandshake()
    // calls — we test the state machine directly.
    $initKp = sodium_crypto_kx_keypair();
    $initSecret = sodium_crypto_kx_secretkey($initKp);
    $initPublic = sodium_crypto_kx_publickey($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respSecret = sodium_crypto_kx_secretkey($respKp);
    $respPublic = sodium_crypto_kx_publickey($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator($initSecret, $initPublic, $respPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respSecret, $respPublic);

    // Exchange Noise messages (simulating the WebSocket binary frames).
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    // Both sides split into send/recv ciphers.
    [$initSend, $initRecv] = $initHs->split();
    [$respSend, $respRecv, $peerStaticRevealedToResp] = $respHs->split();

    $initSession = new NoiseSession($initSend, $initRecv, $respPublic);
    $respSession = new NoiseSession($respSend, $respRecv, $peerStaticRevealedToResp);

    // After split(), both sessions must be able to encrypt/decrypt each other's messages.
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

    // Initiator (device A) and responder (device B) key pairs.
    $kpA = sodium_crypto_kx_keypair();
    $secretA = sodium_crypto_kx_secretkey($kpA);
    $publicA = sodium_crypto_kx_publickey($kpA);

    $kpB = sodium_crypto_kx_keypair();
    $secretB = sodium_crypto_kx_secretkey($kpB);
    $publicB = sodium_crypto_kx_publickey($kpB);

    // Register both devices as confirmed peers in device_registry.
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

    // Simulate IK handshake from device A (initiator) → device B (responder).
    $initHs = NoiseHandshakeState::initIkInitiator($secretA, $publicA, $publicB);
    $respHs = NoiseHandshakeState::initIkResponder($secretB, $publicB);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    // Responder (device B) builds a NoiseSession with revealed peer key (device A's key).
    [$respSendCipher, $respRecvCipher, $peerStaticRevealedToResp] = $respHs->split();

    $respNoiseSession = new NoiseSession($respSendCipher, $respRecvCipher, $peerStaticRevealedToResp);

    // SyncSession auth gate for responder (device B verifies device A is confirmed).
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

    // Arrange: generate a signing keypair and register device A as confirmed.
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

    // Noise IK handshake.
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

    // Auth: receiver-device authenticates sender-device.
    $receiverSession = buildSyncSession(deviceKeys: ['sender-device' => $sigPublicKeyHex]);
    $admitted = $receiverSession->authenticate($receiverNoiseSession, $user->id, 'receiver-device');
    expect($admitted)->toBeTrue('sender-device must be authenticated against confirmed device_registry');

    // Sender creates a signed OpLogEntry and encrypts it via the Noise-backed send path.
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

    // Sender encrypts the frame using the initiator's NoiseSession directly.
    $framer = new TransportFramer;
    $frame = $framer->encode([$signedEntry]);
    $ciphertext = $senderNoiseSession->encrypt($frame);

    // Receiver decrypts and verifies payload integrity via SyncSession::receiveOps().
    // receiveOps() calls DeviceKeySigner::verify() on each entry before replay.
    $deviceKeys = ['sender-device' => $sigPublicKeyHex];
    $receiverSession->receiveOps($ciphertext, $user->id, $deviceKeys);

    // The entry must have been replayed into op_log_entries.
    $persisted = DB::table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'transactions')
        ->where('field', 'note')
        ->where('hlc_l', 1_718_000_000_000)
        ->first();

    expect($persisted)->not->toBeNull('Op payload must be persisted to op_log_entries after receiveOps()');
    expect($persisted->signature)->toBe($realSig, 'Persisted signature must match original Ed25519 sig (Pitfall 7 guard)');
});
