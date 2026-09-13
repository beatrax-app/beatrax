<?php

declare(strict_types=1);

// The attestation half of the ceremony, driven with a real key pair. Nothing
// past the response-type check had ever run: the wrap, the shield and the
// stored row were all reached for the first time here.

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Auth\Tests\Support\VirtualAuthenticator;
use Modules\Core\Models\User;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

const ENROLMENT_RP_ID = 'beatrax.test';

const ENROLMENT_ORIGIN = 'https://beatrax.test';

function enrollingUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
}

function creationChallenge(WebAuthnBiometricService $service, User $user, Session $session): string
{
    $service->creationOptions($user->id, $user->username, $session);

    $encoded = $session->get(WebAuthnBiometricService::CREATION_CHALLENGE_SESSION);
    expect($encoded)->toBeString();

    $challenge = base64_decode((string) $encoded, strict: true);
    expect($challenge)->not->toBeFalse();

    return (string) $challenge;
}

it('stores a credential whose wrapped blob gives the data key back', function (): void {
    $user = enrollingUser('enrol-happy');

    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var AppLockKeyWrap $keyWrap */
    $keyWrap = app()->make(AppLockKeyWrap::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $authenticator = new VirtualAuthenticator;
    $dataKey = random_bytes(32);
    $challenge = creationChallenge($service, $user, $session);

    $service->completeEnrollment(
        $user->id,
        $user->username,
        $authenticator->attestation(ENROLMENT_RP_ID, ENROLMENT_ORIGIN, $challenge),
        $dataKey,
        'Virtual Device',
        BiometricDeviceStore::PLATFORM_WEBAUTHN,
        $session,
    );

    $row = $store->findByCredentialId($user->id, base64_encode($authenticator->credentialId));
    expect($row)->not->toBeNull();
    /** @var stdClass $row */
    expect($row->public_key_cbor)->toBe($authenticator->publicKeyCbor());
    expect((int) $row->counter)->toBe(0);

    // The blob is secret(32B) || wrapped key, and the test shield leaves the
    // bytes alone, so the row is unwrapped here the way an unlock unwraps it.
    /** @var string $blob */
    $blob = $row->biometric_wrap_secret;
    $secret = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrapped = base64_encode(substr($blob, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

    expect($keyWrap->unwrap($wrapped, $secret))->toBe($dataKey);

    // The challenge is spent, so the same attestation cannot enrol twice.
    expect($session->has(WebAuthnBiometricService::CREATION_CHALLENGE_SESSION))->toBeFalse();
});

it('refuses an attestation the authenticator never verified the user for', function (): void {
    $user = enrollingUser('enrol-nouv');

    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $authenticator = new VirtualAuthenticator;
    $challenge = creationChallenge($service, $user, $session);

    // Present but not verified: the options asked for userVerification
    // 'required', and this is what an authenticator that did not do it sends.
    $call = fn () => $service->completeEnrollment(
        $user->id,
        $user->username,
        $authenticator->attestation(
            ENROLMENT_RP_ID,
            ENROLMENT_ORIGIN,
            $challenge,
            flags: VirtualAuthenticator::FLAG_USER_PRESENT | VirtualAuthenticator::FLAG_ATTESTED_CREDENTIAL_DATA,
        ),
        random_bytes(32),
        'Virtual Device',
        BiometricDeviceStore::PLATFORM_WEBAUTHN,
        $session,
    );

    expect($call)->toThrow(AuthenticatorResponseVerificationException::class, 'User authentication required.');
    expect($store->findForUser($user->id))->toHaveCount(0);
});

it('refuses an attestation collected at another origin', function (): void {
    $user = enrollingUser('enrol-origin');

    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $authenticator = new VirtualAuthenticator;
    $challenge = creationChallenge($service, $user, $session);

    $call = fn () => $service->completeEnrollment(
        $user->id,
        $user->username,
        $authenticator->attestation(ENROLMENT_RP_ID, 'https://beatrax.test.evil.example', $challenge),
        random_bytes(32),
        'Virtual Device',
        BiometricDeviceStore::PLATFORM_WEBAUTHN,
        $session,
    );

    expect($call)->toThrow(AuthenticatorResponseVerificationException::class, 'Invalid origin. Not in the list of allowed origins.');
    expect($store->findForUser($user->id))->toHaveCount(0);
});

it('tells the browser to exclude every credential already enrolled', function (): void {
    $user = enrollingUser('enrol-exclude');

    /** @var WebAuthnBiometricService $service */
    $service = app()->make(WebAuthnBiometricService::class);
    /** @var BiometricDeviceStore $store */
    $store = app()->make(BiometricDeviceStore::class);
    /** @var Session $session */
    $session = app()->make(Session::class);

    $enrolled = new VirtualAuthenticator;
    $store->store(
        $user->id,
        base64_encode($enrolled->credentialId),
        'Already Enrolled',
        str_repeat("\x33", 32),
        $enrolled->publicKeyCbor(),
        BiometricDeviceStore::PLATFORM_WEBAUTHN,
    );
    // A stored id that is not base64: nothing can be offered for it, and it
    // must not take the whole list down with it.
    $store->store($user->id, 'not base64 %%%', 'Unreadable', str_repeat("\x44", 32), 'fake-cbor', BiometricDeviceStore::PLATFORM_WEBAUTHN);

    $options = $service->creationOptions($user->id, $user->username, $session);

    /** @var array<int, array<string, mixed>> $exclude */
    $exclude = $options['excludeCredentials'] ?? [];
    expect($exclude)->toHaveCount(1);
    expect($exclude[0]['id'] ?? null)->toBe(VirtualAuthenticator::b64url($enrolled->credentialId));

    // The requirement the validator reads back out of these same options.
    /** @var array<string, mixed> $selection */
    $selection = $options['authenticatorSelection'] ?? [];
    expect($selection['userVerification'] ?? null)->toBe('required');
});
