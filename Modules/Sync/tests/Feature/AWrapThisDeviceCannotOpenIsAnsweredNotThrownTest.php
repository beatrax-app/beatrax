<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapOutcome;
use Modules\Sync\Internal\Crypto\GdkWrapRecipient;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// A sealed box is opened last, behind the envelope, recipient, sender and role
// gates, so the only wraps that reach the open are ones every earlier gate
// passed. What happens there is a contract of its own: an answer, never an
// escape, because the caller holding the mailbox row decides on the answer.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
function unopenedWrapUser(): User
{
    $user = User::query()->create([
        'username' => 'unopened-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // The keyring is a file, which RefreshDatabase does not roll back, and
    // SQLite reuses rowids — so a fresh user can inherit an earlier keyring.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    return $user;
}

/**
 * @return array{0: DeviceIdentityDto, 1: string, 2: string}
 */
function unopenedWrapSelfAndSender(User $user, Session $session): array
{
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);

    $signingKeypair = sodium_crypto_sign_keypair();
    $sealingKeypair = sodium_crypto_box_keypair();
    $senderId = 'unopened-sender-'.bin2hex(random_bytes(4));

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => $senderId,
        'name' => $senderId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($signingKeypair)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($sealingKeypair)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    return [$self, $senderId, sodium_bin2hex(sodium_crypto_sign_secretkey($signingKeypair))];
}

// The signature covers the sealed bytes and the recipient's device ID, never
// the public key they were sealed against, so a wrap addressed here and sealed
// elsewhere verifies and then fails to open — which is the whole point of the
// separation and the only honest way to reach that branch.
function unopenedWrapJson(
    DeviceIdentityDto $self,
    string $senderId,
    string $senderSecretHex,
    string $sealAgainstPublicBin,
    int $epochId,
    string $rawKey,
): string {
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    return json_encode($rotation->buildGdkEpochWrap(
        $epochId,
        $rawKey,
        new GdkWrapRecipient($self->deviceId, $sealAgainstPublicBin),
        $senderId,
        $senderSecretHex,
    ), JSON_THROW_ON_ERROR);
}

it('refuses a signed wrap whose sealed box will not open here, without throwing and without appending', function (): void {
    $user = unopenedWrapUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = unopenedWrapSelfAndSender($user, $session);

    $strangerPublic = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
    $json = unopenedWrapJson(
        $self,
        $senderId,
        $senderSecretHex,
        $strangerPublic,
        5150,
        random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
    );

    $thrown = null;
    $outcome = null;

    try {
        $outcome = app(GdkEpochControlHandler::class)->handle($json, (int) $user->id, $session);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull(
        'a sealed box that will not open must be answered, not thrown: the listener has no catch of its own, '
        .'and an escape there aborts the session that was carrying the rest of the fan-out — '
        .($thrown === null ? '' : $thrown::class.': '.$thrown->getMessage()),
    );

    expect($outcome)->toBe(
        GdkWrapOutcome::Refused,
        'a wrap this device cannot open is provably invalid for it however often it is redelivered, so it is refused',
    );

    expect(app(GdkKeyringService::class)->loadKeyring((int) $user->id, $session)->keyFor(5150))->toBeNull(
        'nothing may reach the keyring for an epoch whose sealed key was never recovered',
    );
});

it('adopts a recovered key whose bytes read as falsy, rather than reading the unseal as a failure', function (): void {
    $user = unopenedWrapUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = unopenedWrapSelfAndSender($user, $session);

    // "0" is the one plaintext PHP reads as false. A loose truthiness check on
    // the unseal cannot tell it from the false that means the box did not open,
    // so this is what separates a strict check from one that looks the same.
    $json = unopenedWrapJson(
        $self,
        $senderId,
        $senderSecretHex,
        sodium_hex2bin($self->x25519PublicKeyHex),
        5151,
        '0',
    );

    $outcome = app(GdkEpochControlHandler::class)->handle($json, (int) $user->id, $session);

    expect($outcome)->toBe(
        GdkWrapOutcome::Applied,
        'the unseal must be judged on the false libsodium returns, never on whether the recovered bytes are truthy',
    );

    expect(app(GdkKeyringService::class)->loadKeyring((int) $user->id, $session)->keyFor(5151))->toBe(
        sodium_bin2hex('0'),
        'a recovered key that opened must be appended whatever its bytes spell',
    );
});

it('logs and returns when key material arrives with the app-lock closed, rather than throwing', function (): void {
    $user = unopenedWrapUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = unopenedWrapSelfAndSender($user, $session);
    $json = unopenedWrapJson(
        $self,
        $senderId,
        $senderSecretHex,
        sodium_hex2bin($self->x25519PublicKeyHex),
        5152,
        random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
    );

    // The listener holds a session no middleware ever started, so this is its
    // permanent state rather than an edge case, and it is where a wrap that
    // arrives to a locked app lands.
    app(AppLockKeyService::class)->withhold($session);

    $logSpy = Mockery::spy(LoggerInterface::class);
    app()->instance(LoggerInterface::class, $logSpy);
    app()->forgetInstance(GdkEpochControlHandler::class);

    $thrown = null;
    $outcome = null;

    try {
        $outcome = app(GdkEpochControlHandler::class)->handle($json, (int) $user->id, $session);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull(
        'key material arriving while the app is locked must be returned, not raised — '
        .($thrown === null ? '' : $thrown::class.': '.$thrown->getMessage()),
    );

    expect($outcome)->toBe(
        GdkWrapOutcome::Deferred,
        'a locked process cannot decide a wrap, so it defers rather than refusing one it never read',
    );

    expect($outcome?->consumesCarrier())->toBeFalse(
        'nothing re-sends a wrap, so a deferred one must leave its carrier in the mailbox for an unlocked pass',
    );

    $logSpy->shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => str_starts_with($message, 'GdkEpochControlHandler:')
            && str_contains($message, 'deferred'))
        ->atLeast()->once();
});
