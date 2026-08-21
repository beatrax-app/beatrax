<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkEpochWrapSignature;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// The rotation takes its Session per call rather than through the constructor:
// the service is a container singleton, so a captured Session goes stale across
// requests and the rotation would sign against a session nobody is in.

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

    // The rotation signs each fan-out wrap, so the acting device needs a real
    // on-disk identity — a bare registry row has no key file to load.
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);
    $removedDeviceId = rotationDeviceRow($db, (int) $user->id, 'removed-device', false);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $rotation->rotateAndRevoke((int) $user->id, $removedDeviceId, $session);

    // A rotation must produce a DIFFERENT key, not a higher number: ids are
    // minted so two devices rotating apart can never name the same key.
    $current = $keyring->currentEpoch((int) $user->id, $session);
    expect($current->epochId)->not->toBe($initial->epochId);
    expect($current->keyHex)->not->toBe($initial->keyHex);
});

it('takes Session as a per-method rotateAndRevoke() parameter, not a constructor field', function (): void {
    $ctorParams = (new ReflectionClass(GdkRotationService::class))->getConstructor()?->getParameters() ?? [];
    $ctorParamTypes = array_map(
        static fn (ReflectionParameter $param): string => (string) $param->getType(),
        $ctorParams,
    );

    expect($ctorParamTypes)->not->toContain(Session::class);

    $methodParams = (new ReflectionMethod(GdkRotationService::class, 'rotateAndRevoke'))->getParameters();
    $methodParamTypes = array_map(
        static fn (ReflectionParameter $param): string => (string) $param->getType(),
        $methodParams,
    );

    expect($methodParamTypes)->toContain(Session::class);
});

it('builds one sealed-box GDK epoch wrap per remaining trusted device', function (): void {
    $user = rotationUser('rotation-wrap-user');

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $recipientKeypair = sodium_crypto_box_keypair();
    $recipientPub = sodium_crypto_box_publickey($recipientKeypair);
    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    // The sender signs the wrap so a recipient can authenticate where it came
    // from, against the public half its own registry already holds.
    $senderSigKp = sodium_crypto_sign_keypair();
    $senderSecretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($senderSigKp));

    $wrap = $rotation->buildGdkEpochWrap(2, $rawGdkKey, $recipientPub, 'remaining-device', 'sender-device', $senderSecretHex);

    expect($wrap)->toHaveKey('type', 'GDK_EPOCH_WRAP');
    expect($wrap)->toHaveKey('epoch_id', 2);
    expect($wrap)->toHaveKey('recipient_device_id', 'remaining-device');
    expect($wrap)->toHaveKey('sender_device_id', 'sender-device');
    expect($wrap['wrapped_key_b64'])->toBeString();
    expect($wrap['sig_hex'])->toBeString();

    $sealed = base64_decode((string) $wrap['wrapped_key_b64'], true);
    expect($sealed)->not->toBeFalse();

    // The signature covers the sealed bytes + epoch id + both device ids.
    $message = GdkEpochWrapSignature::signingMessage(2, (string) $sealed, 'remaining-device', 'sender-device');
    expect((new DeviceKeySigner)->verify($message, (string) $wrap['sig_hex'], sodium_crypto_sign_publickey($senderSigKp)))
        ->toBeTrue('the wrap must carry a valid detached Ed25519 signature from the sender');

    $unwrapped = sodium_crypto_box_seal_open((string) $sealed, $recipientKeypair);
    expect($unwrapped)->not->toBeFalse();
    expect($unwrapped)->toBe($rawGdkKey);
});
