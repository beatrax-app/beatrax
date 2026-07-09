<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;

uses(RefreshDatabase::class);

/*
 * GdkRotationServiceTest — CRYPT-02: rotateAndRevoke generates epoch N+1,
 * appends it to the acting device's keyring, and produces one sealed-box
 * wrap per remaining trusted device. 14-VALIDATION.md CRYPT-02 row 1.
 *
 * RED until Plan 05 ships Modules\Sync\Internal\Crypto\GdkRotationService.
 * This test references the planned production FQCN, which does not yet
 * exist — the failure is "class not found", the correct Wave 0 RED state.
 */

function rotationUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rotationDeviceRow(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf): int
{
    return $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

it('generates a new GDK epoch N+1 and appends it to the acting device keyring on device removal', function (): void {
    $user = rotationUser('rotation-user');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $initial = $keyring->generateAndPersist((int) $user->id, $session);

    rotationDeviceRow($db, (int) $user->id, 'self-device', true);
    $removedDeviceId = rotationDeviceRow($db, (int) $user->id, 'removed-device', false);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $rotation->rotateAndRevoke((int) $user->id, $removedDeviceId);

    $current = $keyring->currentEpoch((int) $user->id, $session);
    expect($current->epochId)->toBeGreaterThan($initial->epochId);
});

it('builds one sealed-box GDK epoch wrap per remaining trusted device', function (): void {
    $user = rotationUser('rotation-wrap-user');

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $recipientKeypair = sodium_crypto_box_keypair();
    $recipientPub = sodium_crypto_box_publickey($recipientKeypair);
    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    $wrap = $rotation->buildGdkEpochWrap(2, $rawGdkKey, $recipientPub, 'remaining-device');

    expect($wrap)->toHaveKey('type', 'GDK_EPOCH_WRAP');
    expect($wrap)->toHaveKey('epoch_id', 2);
    expect($wrap)->toHaveKey('recipient_device_id', 'remaining-device');
    expect($wrap['wrapped_key_b64'])->toBeString();

    $unwrapped = sodium_crypto_box_seal_open(base64_decode((string) $wrap['wrapped_key_b64'], true), $recipientKeypair);
    expect($unwrapped)->not->toBeFalse();
    expect($unwrapped)->toBe($rawGdkKey);
});
