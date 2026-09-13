<?php

declare(strict_types=1);

// Every test here drives the real ceremony with a real ES256 key pair, so the
// pass is the validator's and not a double's. Without one, nothing past
// CheckSignature had ever run: releaseDataKey() was reached by reflection
// only, and no test had ever seen an assertion succeed.

use Illuminate\Contracts\Session\Session;
use Illuminate\Session\SessionManager;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Tests\Support\VirtualAuthenticator;
use Modules\Core\Models\User;

const CEREMONY_RP_ID = 'beatrax.test';

const CEREMONY_ORIGIN = 'https://beatrax.test';

function ceremonyUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
}

/**
 * @return array{0: VirtualAuthenticator, 1: string, 2: stdClass}
 */
function enrolledAuthenticator(User $user, string $dataKey): array
{
    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var AppLockKeyWrap $keyWrap */
    $keyWrap = app()->make(AppLockKeyWrap::class);

    $authenticator = new VirtualAuthenticator;

    $secret = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrappedBytes = base64_decode($keyWrap->wrap($dataKey, $secret), strict: true);
    expect($wrappedBytes)->not->toBeFalse();

    $credentialId = base64_encode($authenticator->credentialId);
    $store->store(
        $user->id,
        $credentialId,
        'Virtual Device',
        $secret.(string) $wrappedBytes,
        $authenticator->publicKeyCbor(),
        BiometricDeviceStore::PLATFORM_WEBAUTHN,
    );

    $row = $store->findByCredentialId($user->id, $credentialId);

    /** @var stdClass $row */
    return [$authenticator, $credentialId, $row];
}

function issuedChallenge(WebAuthnBiometricService $service, int $userId, Session $session): string
{
    $service->requestOptions($userId, $session);

    $encoded = $session->get(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION);
    expect($encoded)->toBeString();

    $challenge = base64_decode((string) $encoded, strict: true);
    expect($challenge)->not->toBeFalse();

    return (string) $challenge;
}

it('releases the data key for an assertion the enrolled credential actually signed', function (): void {
    $user = ceremonyUser('ceremony-happy');
    $dataKey = random_bytes(32);
    [$authenticator, $credentialId, $row] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // Driven up first so the reset is provable rather than vacuous.
    $store->incrementFailureCount((int) $row->id);
    $lockState->lock($session);

    $challenge = issuedChallenge($service, $user->id, $session);

    $unlocked = $service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $challenge, signCount: 7),
        $session,
    );

    expect($unlocked)->toBeTrue();
    expect($lockState->isLocked($session))->toBeFalse();
    expect($keyService->release($session))->toBe($dataKey);

    $after = $store->findByCredentialId($user->id, $credentialId);
    /** @var stdClass $after */
    expect((int) $after->counter)->toBe(7);
    expect((int) $after->biometric_failed_count)->toBe(0);
});

it('refuses the same assertion presented a second time', function (): void {
    $user = ceremonyUser('ceremony-replay');
    $dataKey = random_bytes(32);
    [$authenticator] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $lockState->lock($session);
    $challenge = issuedChallenge($service, $user->id, $session);
    $assertion = $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $challenge, signCount: 3);

    expect($service->verifyAndRelease($user->id, $assertion, $session))->toBeTrue();

    $lockState->lock($session);

    // The challenge was pulled, not read, so the captured assertion has
    // nothing left to match against.
    expect($service->verifyAndRelease($user->id, $assertion, $session))->toBeFalse();
    expect($lockState->isLocked($session))->toBeTrue();
});

it('refuses an assertion whose signature counter did not advance', function (): void {
    $user = ceremonyUser('ceremony-counter');
    $dataKey = random_bytes(32);
    [$authenticator, $credentialId] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $lockState->lock($session);
    $first = issuedChallenge($service, $user->id, $session);
    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $first, signCount: 4),
        $session,
    ))->toBeTrue();

    $lockState->lock($session);

    // A fresh challenge, a fresh signature, and a counter that stood still —
    // the shape a cloned authenticator produces.
    $second = issuedChallenge($service, $user->id, $session);
    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $second, signCount: 4),
        $session,
    ))->toBeFalse();

    expect($lockState->isLocked($session))->toBeTrue();

    $after = $store->findByCredentialId($user->id, $credentialId);
    /** @var stdClass $after */
    expect((int) $after->counter)->toBe(4);
    expect((int) $after->biometric_failed_count)->toBe(1);
});

it('refuses an assertion signed for another origin', function (): void {
    $user = ceremonyUser('ceremony-origin');
    $dataKey = random_bytes(32);
    [$authenticator] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $lockState->lock($session);
    $challenge = issuedChallenge($service, $user->id, $session);

    // Everything else is genuine: the same key, the same rpId, the live
    // challenge. Only the origin the page collected differs.
    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(CEREMONY_RP_ID, 'https://beatrax.test.evil.example', $challenge),
        $session,
    ))->toBeFalse();

    expect($lockState->isLocked($session))->toBeTrue();
});

it('refuses an assertion whose rpId hash names another relying party', function (): void {
    $user = ceremonyUser('ceremony-rpid');
    $dataKey = random_bytes(32);
    [$authenticator] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $challenge = issuedChallenge($service, $user->id, $session);

    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion('evil.example', CEREMONY_ORIGIN, $challenge),
        $session,
    ))->toBeFalse();
});

it('refuses an assertion the authenticator never verified the user for', function (): void {
    $user = ceremonyUser('ceremony-nouv');
    $dataKey = random_bytes(32);
    [$authenticator] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $challenge = issuedChallenge($service, $user->id, $session);

    // Present but not verified: a tap, with no fingerprint behind it.
    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(
            CEREMONY_RP_ID,
            CEREMONY_ORIGIN,
            $challenge,
            flags: VirtualAuthenticator::FLAG_USER_PRESENT,
        ),
        $session,
    ))->toBeFalse();
});

it('refuses an assertion for a credential another account enrolled', function (): void {
    $owner = ceremonyUser('ceremony-owner');
    $intruder = ceremonyUser('ceremony-intruder');
    $dataKey = random_bytes(32);
    [$authenticator, $credentialId] = enrolledAuthenticator($owner, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $challenge = issuedChallenge($service, $intruder->id, $session);

    expect($service->verifyAndRelease(
        $intruder->id,
        $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $challenge),
        $session,
    ))->toBeFalse();

    // The owner's own credential must not be throttled by somebody else's try.
    $after = $store->findByCredentialId($owner->id, $credentialId);
    /** @var stdClass $after */
    expect((int) $after->biometric_failed_count)->toBe(0);
});

it('refuses an assertion against a challenge a different session was issued', function (): void {
    $user = ceremonyUser('ceremony-session');
    $dataKey = random_bytes(32);
    [$authenticator] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var SessionManager $sessions */
    $sessions = $this->app->make(SessionManager::class);
    /** @var Session $issuedTo */
    $issuedTo = $this->app->make(Session::class);

    $challenge = issuedChallenge($service, $user->id, $issuedTo);

    $other = $sessions->driver('array');
    $other->start();

    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $challenge),
        $other,
    ))->toBeFalse();

    // And the challenge the other session could not use is still the one this
    // session holds.
    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $challenge),
        $issuedTo,
    ))->toBeTrue();
});

it('stays locked when the verified credential\'s stored key material does not open', function (): void {
    $user = ceremonyUser('ceremony-corrupt');

    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var LockStateManager $lockState */
    $lockState = app()->make(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = app()->make(AppLockKeyService::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $authenticator = new VirtualAuthenticator;

    // The right length for secret || wrapped key, and the wrapped half is
    // noise: the ceremony passes and the unwrap then cannot.
    $credentialId = base64_encode($authenticator->credentialId);
    $store->store(
        $user->id,
        $credentialId,
        'Corrupt Device',
        random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES + 48),
        $authenticator->publicKeyCbor(),
        BiometricDeviceStore::PLATFORM_WEBAUTHN,
    );

    $row = $store->findByCredentialId($user->id, $credentialId);
    /** @var stdClass $row */
    $store->incrementFailureCount((int) $row->id);

    $lockState->lock($session);
    $challenge = issuedChallenge($service, $user->id, $session);

    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->assertion(CEREMONY_RP_ID, CEREMONY_ORIGIN, $challenge, signCount: 9),
        $session,
    ))->toBeFalse();

    expect($lockState->isLocked($session))->toBeTrue();
    expect($keyService->release($session))->toBeNull();

    // The assertion did verify, so the counter moved and the throttle was
    // cleared before the blob turned out to be unreadable.
    $after = $store->findByCredentialId($user->id, $credentialId);
    /** @var stdClass $after */
    expect((int) $after->counter)->toBe(9);
    expect((int) $after->biometric_failed_count)->toBe(0);
});

it('refuses an enrolment response posted to the unlock endpoint', function (): void {
    $user = ceremonyUser('ceremony-wrongceremony');
    $dataKey = random_bytes(32);
    [$authenticator, $credentialId] = enrolledAuthenticator($user, $dataKey);

    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $challenge = issuedChallenge($service, $user->id, $session);

    expect($service->verifyAndRelease(
        $user->id,
        $authenticator->attestation(CEREMONY_RP_ID, CEREMONY_ORIGIN, $challenge),
        $session,
    ))->toBeFalse();

    // Refused before any credential was looked up, so no attempt was charged
    // against the row — but the challenge is spent either way.
    $after = $store->findByCredentialId($user->id, $credentialId);
    /** @var stdClass $after */
    expect((int) $after->biometric_failed_count)->toBe(0);
    expect($session->has(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION))->toBeFalse();
});

it('offers only the credentials whose stored id is readable', function (): void {
    $user = ceremonyUser('ceremony-unreadable');

    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $good = new VirtualAuthenticator;
    $store->store($user->id, base64_encode($good->credentialId), 'Readable', str_repeat("\x11", 32), $good->publicKeyCbor(), BiometricDeviceStore::PLATFORM_WEBAUTHN);
    // Stored id that is not base64 at all: the browser can be offered nothing
    // for it, and the ceremony could never match it either.
    $store->store($user->id, 'not base64 %%%', 'Unreadable', str_repeat("\x22", 32), 'fake-cbor', BiometricDeviceStore::PLATFORM_WEBAUTHN);

    $options = $service->requestOptions($user->id, $session);

    /** @var array<int, array<string, mixed>> $allowCredentials */
    $allowCredentials = $options['allowCredentials'] ?? [];
    expect($allowCredentials)->toHaveCount(1);
    expect($allowCredentials[0]['id'] ?? null)->toBe(VirtualAuthenticator::b64url($good->credentialId));
});

it('falls back to localhost when the configured app URL is not a string', function (): void {
    $user = ceremonyUser('ceremony-badurl');

    config()->set('app.url', ['not', 'a', 'url']);

    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $options = $service->requestOptions($user->id, $session);

    expect($options['rpId'] ?? null)->toBe('localhost');
});
