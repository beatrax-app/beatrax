<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// Handshake cryptography succeeding proves only that the peer holds a key, not
// that this user ever confirmed it. The revealed static key is checked against
// the confirmed, user-scoped registry afterwards — the transport-level analog
// of the replayer's own device-key gate.

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

    $deviceId = 'auth-test-device-'.bin2hex(random_bytes(4));
    DB::table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => $deviceId,
        'name' => 'Auth Test Device',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => sodium_bin2hex($initStaticPublic),
        'safety_number_words' => 'word one two three four five',
        'is_self' => 0,
        'paired_at' => now()->toIso8601String(),
        'confirmed_at' => now()->toIso8601String(),  // CONFIRMED
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $initHs = NoiseHandshakeState::initIkInitiator($initStaticSecret, $initStaticPublic, $respStaticPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respStaticSecret, $respStaticPublic);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [, , $peerStaticFromResp] = $respHs->split();

    $registryService = app(DeviceRegistryService::class);
    $confirmedKeys = $registryService->deviceX25519Keys($user->id);

    $peerKeyHex = sodium_bin2hex($peerStaticFromResp);
    expect(in_array($peerKeyHex, $confirmedKeys, true))->toBeTrue(
        'Confirmed device X25519 key must be in deviceX25519Keys() → session admitted'
    );
});

it('rejects a Noise session whose static key is not in device_registry (unconfirmed device)', function (): void {
    $user = authTestUser('auth-user-2');

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

    DB::table('device_registry')->insert([
        'user_id' => $userB->id,
        'device_id' => 'cross-user-device-b',
        'name' => 'User B Device',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => sodium_bin2hex($initStaticPublic),
        'safety_number_words' => 'word one two three four five',
        'is_self' => 0,
        'paired_at' => now()->toIso8601String(),
        'confirmed_at' => now()->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $registryService = app(DeviceRegistryService::class);
    $confirmedKeysForUserA = $registryService->deviceX25519Keys($userA->id);

    $peerKeyHex = sodium_bin2hex($initStaticPublic);
    expect(in_array($peerKeyHex, $confirmedKeysForUserA, true))->toBeFalse(
        'Cross-user: user B device key must not appear in user A deviceX25519Keys() (T-13-01 user scope)'
    );

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
        'user_id' => $user->id,
        'device_id' => $deviceId,
        'name' => 'To Be Revoked',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => sodium_bin2hex($initStaticPublic),
        'safety_number_words' => 'word one two three four five',
        'is_self' => 0,
        'paired_at' => now()->toIso8601String(),
        'confirmed_at' => now()->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $registryService = app(DeviceRegistryService::class);
    $peerKeyHex = sodium_bin2hex($initStaticPublic);

    $beforeKeys = $registryService->deviceX25519Keys($user->id);
    expect(in_array($peerKeyHex, $beforeKeys, true))->toBeTrue('Key must be present before revocation');

    DB::table('device_registry')->where('device_id', $deviceId)->delete();

    $afterKeys = $registryService->deviceX25519Keys($user->id);
    expect(in_array($peerKeyHex, $afterKeys, true))->toBeFalse(
        'After revocation, device key must not appear in deviceX25519Keys() → new sessions rejected'
    );
});
