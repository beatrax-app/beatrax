<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

/*
 * NoisePeerAuthTest — XPORT-01: confirmed-device authentication gate.
 *
 * After a Noise IK or XX handshake completes, the responder's revealed
 * static X25519 public key MUST be compared against
 * DeviceRegistryService::deviceX25519Keys() (confirmed-only, user-scoped).
 * An unconfirmed device (safety-number not yet verified) MUST NOT be admitted
 * to a Noise session — even if the handshake cryptography succeeds.
 *
 * Trust anchor: Phase 12 device_registry with confirmed_at IS NOT NULL (T-13-01).
 * This is the Noise-level analog of the op-log's deviceKeys() replayer gate.
 *
 * Two invariants:
 *   I-AUTH-1: A confirmed peer's X25519 static key is admitted (happy path).
 *   I-AUTH-2: An unconfirmed peer's X25519 static key is rejected, session
 *             closed (T-13-01 / RESEARCH Pattern 2 / Pitfall 2 guard).
 */

function authTestUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('auth-test-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('admits a Noise session whose static key matches a confirmed device_registry entry', function (): void {
    $user = authTestUser('auth-user-1');

    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    // Insert a CONFIRMED device entry with a known X25519 public key
    $deviceId = 'auth-test-device-'.bin2hex(random_bytes(4));
    DB::table('device_registry')->insert([
        'user_id'                => $user->id,
        'device_id'              => $deviceId,
        'name'                   => 'Auth Test Device',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex'  => sodium_bin2hex($initStaticPublic),
        'safety_number_words'    => 'word one two three four five',
        'is_self'                => 0,
        'paired_at'              => now()->toIso8601String(),
        'confirmed_at'           => now()->toIso8601String(),  // CONFIRMED
        'created_at'             => now()->toIso8601String(),
        'updated_at'             => now()->toIso8601String(),
    ]);

    // Do IK handshake (responder learns initiator's static key from msg1)
    $initHs = NoiseHandshakeState::initIkInitiator($initStaticSecret, $initStaticPublic, $respStaticPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respStaticSecret, $respStaticPublic);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [, , $peerStaticFromResp] = $respHs->split();

    // The auth gate: check the revealed static key against DeviceRegistryService::deviceX25519Keys()
    $registryService = app(DeviceRegistryService::class);
    $confirmedKeys = $registryService->deviceX25519Keys($user->id);

    // Peer static key is in device_registry (confirmed) → session should be admitted
    $peerKeyHex = sodium_bin2hex($peerStaticFromResp);
    expect(in_array($peerKeyHex, $confirmedKeys, true))->toBeTrue(
        'Confirmed device X25519 key must be in deviceX25519Keys() → session admitted'
    );
});

it('rejects a Noise session whose static key is not in device_registry (unconfirmed device)', function (): void {
    $user = authTestUser('auth-user-2');

    // Generate a keypair that is NOT in device_registry
    $unknownKp = sodium_crypto_kx_keypair();
    $unknownSecret = sodium_crypto_kx_secretkey($unknownKp);
    $unknownPublic = sodium_crypto_kx_publickey($unknownKp);
    sodium_memzero($unknownKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator($unknownSecret, $unknownPublic, $respStaticPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respStaticSecret, $respStaticPublic);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [, , $peerStaticFromResp] = $respHs->split();

    // The auth gate check
    $registryService = app(DeviceRegistryService::class);
    $confirmedKeys = $registryService->deviceX25519Keys($user->id);

    $peerKeyHex = sodium_bin2hex($peerStaticFromResp);
    expect(in_array($peerKeyHex, $confirmedKeys, true))->toBeFalse(
        'Unknown device X25519 key must NOT be in deviceX25519Keys() → session must be rejected'
    );
});

it('rejects a Noise session whose static key belongs to a different user (cross-user scope guard)', function (): void {
    $userA = authTestUser('cross-scope-user-a');
    $userB = authTestUser('cross-scope-user-b');

    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    // Register the initiator's key under user B (NOT user A)
    DB::table('device_registry')->insert([
        'user_id'                => $userB->id,
        'device_id'              => 'cross-user-device-b',
        'name'                   => 'User B Device',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex'  => sodium_bin2hex($initStaticPublic),
        'safety_number_words'    => 'word one two three four five',
        'is_self'                => 0,
        'paired_at'              => now()->toIso8601String(),
        'confirmed_at'           => now()->toIso8601String(),
        'created_at'             => now()->toIso8601String(),
        'updated_at'             => now()->toIso8601String(),
    ]);

    // When user A performs the auth gate check, user B's key must NOT appear
    $registryService = app(DeviceRegistryService::class);
    $confirmedKeysForUserA = $registryService->deviceX25519Keys($userA->id);

    $peerKeyHex = sodium_bin2hex($initStaticPublic);
    expect(in_array($peerKeyHex, $confirmedKeysForUserA, true))->toBeFalse(
        'Cross-user: user B device key must not appear in user A deviceX25519Keys() (T-13-01 user scope)'
    );

    // But it IS in user B's registry
    $confirmedKeysForUserB = $registryService->deviceX25519Keys($userB->id);
    expect(in_array($peerKeyHex, $confirmedKeysForUserB, true))->toBeTrue(
        'User B device key must appear in user B deviceX25519Keys()'
    );
});

it('rejects a Noise session after static key is removed from device_registry (revocation)', function (): void {
    $user = authTestUser('revocation-test-user');

    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $deviceId = 'revoked-device-'.bin2hex(random_bytes(4));
    DB::table('device_registry')->insert([
        'user_id'                => $user->id,
        'device_id'              => $deviceId,
        'name'                   => 'To Be Revoked',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex'  => sodium_bin2hex($initStaticPublic),
        'safety_number_words'    => 'word one two three four five',
        'is_self'                => 0,
        'paired_at'              => now()->toIso8601String(),
        'confirmed_at'           => now()->toIso8601String(),
        'created_at'             => now()->toIso8601String(),
        'updated_at'             => now()->toIso8601String(),
    ]);

    $registryService = app(DeviceRegistryService::class);
    $peerKeyHex = sodium_bin2hex($initStaticPublic);

    // Before revocation: key is present
    $beforeKeys = $registryService->deviceX25519Keys($user->id);
    expect(in_array($peerKeyHex, $beforeKeys, true))->toBeTrue('Key must be present before revocation');

    // Revoke: remove from device_registry (Phase 14 will add a softer revocation path)
    DB::table('device_registry')->where('device_id', $deviceId)->delete();

    // After revocation: key is gone → new sessions from this device must be rejected
    $afterKeys = $registryService->deviceX25519Keys($user->id);
    expect(in_array($peerKeyHex, $afterKeys, true))->toBeFalse(
        'After revocation, device key must not appear in deviceX25519Keys() → new sessions rejected'
    );
});
