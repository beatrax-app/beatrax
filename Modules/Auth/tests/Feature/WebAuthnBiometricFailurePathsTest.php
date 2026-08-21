<?php

declare(strict_types=1);

// navigator.credentials.* cannot be automated, so these drive the server-side
// error branches directly.

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Exceptions\BiometricChallengeException;
use Modules\Auth\Internal\Exceptions\BiometricEnrollmentException;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

function credentialRecordWithCounter(string $rawId, int $counter): CredentialRecord
{
    return CredentialRecord::create(
        publicKeyCredentialId: $rawId,
        type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
        transports: [],
        attestationType: 'none',
        trustPath: EmptyTrustPath::create(),
        aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
        credentialPublicKey: 'fake-cbor',
        userHandle: '1',
        counter: $counter,
    );
}

// base64url without padding — the encoding lock.js uses on the wire.
function b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

// Structurally valid, cryptographically bogus: the serializer accepts it and
// the ceremony validator rejects it, which is the failure under test.
/**
 * @return array<string, mixed>
 */
function fakeAssertion(string $rawId): array
{
    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => b64url(random_bytes(32)),
        'origin' => 'http://localhost',
    ], JSON_THROW_ON_ERROR);

    // rpIdHash(32) || flags(1) || signCount(4). Flags 0x05 is User Present +
    // User Verified with neither the attested-credential-data (0x40) nor the
    // extension (0x80) bit, so no trailing AAGUID block is read.
    $authenticatorData = random_bytes(32)."\x05"."\x00\x00\x00\x00";

    return [
        'id' => b64url($rawId),
        'rawId' => b64url($rawId),
        'type' => 'public-key',
        'response' => [
            'authenticatorData' => b64url($authenticatorData),
            'clientDataJSON' => b64url($clientData),
            'signature' => b64url(random_bytes(64)),
            'userHandle' => null,
        ],
    ];
}

function biometricUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
}

it('completeEnrollment throws BiometricChallengeException::missing when no challenge is in the session', function (): void {
    $user = biometricUser('enroll-nochallenge');

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $call = fn () => $service->completeEnrollment(
        $user->id,
        $user->username,
        fakeAssertion(random_bytes(16)),
        random_bytes(32),
        'Test Device',
        'webauthn',
        $session,
    );

    expect($call)->toThrow(BiometricChallengeException::class, 'No pending creation challenge in session.');
});

it('completeEnrollment throws BiometricChallengeException::malformedEncoding when the stored challenge is not base64', function (): void {
    $user = biometricUser('enroll-badchallenge');

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // A non-base64 string survives the is_string() guard but fails strict decode.
    $session->put(WebAuthnBiometricService::CREATION_CHALLENGE_SESSION, 'not valid base64 %%%');

    $call = fn () => $service->completeEnrollment(
        $user->id,
        $user->username,
        fakeAssertion(random_bytes(16)),
        random_bytes(32),
        'Test Device',
        'webauthn',
        $session,
    );

    expect($call)->toThrow(BiometricChallengeException::class, 'Invalid creation challenge encoding.');
});

it('completeEnrollment throws BiometricEnrollmentException when the payload is an assertion, not an attestation', function (): void {
    $user = biometricUser('enroll-wrongresponse');

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // A valid challenge clears consumeCreationChallenge(), then the payload
    // turns out to be a get()-style response: the wrong ceremony.
    $session->put(WebAuthnBiometricService::CREATION_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    $call = fn () => $service->completeEnrollment(
        $user->id,
        $user->username,
        fakeAssertion(random_bytes(16)),
        random_bytes(32),
        'Test Device',
        'webauthn',
        $session,
    );

    expect($call)->toThrow(BiometricEnrollmentException::class, 'Expected attestation response.');
});

it('verifyAndRelease runs the full assertion ceremony and throttles on a bogus signature', function (): void {
    $user = biometricUser('verify-ceremony');

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // A string CBOR public key lets buildCredentialRecord() succeed, so the
    // ceremony runs and rejects the fabricated signature rather than bailing.
    $rawId = random_bytes(16);
    $store->store($user->id, base64_encode($rawId), 'Ceremony Device', str_repeat("\x11", 32), 'fake-cbor', 'webauthn');

    $session->put(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    $result = $service->verifyAndRelease($user->id, fakeAssertion($rawId), $session);

    expect($result)->toBeFalse();

    $cred = $store->findByCredentialId($user->id, base64_encode($rawId));
    /** @var stdClass $cred */
    expect((int) $cred->biometric_failed_count)->toBe(1);
});

it('verifyAndRelease throttles when the stored credential has no public key CBOR', function (): void {
    $user = biometricUser('verify-nocbor');

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // A null public_key_cbor bails before a record exists, so the throttle
    // takes the hit instead.
    $rawId = random_bytes(16);
    $store->store($user->id, base64_encode($rawId), 'No CBOR Device', str_repeat("\x22", 32), null, 'webauthn');

    $session->put(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    $result = $service->verifyAndRelease($user->id, fakeAssertion($rawId), $session);

    expect($result)->toBeFalse();

    $cred = $store->findByCredentialId($user->id, base64_encode($rawId));
    /** @var stdClass $cred */
    expect((int) $cred->biometric_failed_count)->toBe(1);
});

it('verifyAndRelease returns false when the assertion references an unknown credential', function (): void {
    $user = biometricUser('verify-unknown');

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $session->put(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    // Deserialises fine, but no credential row exists for this rawId.
    $result = $service->verifyAndRelease($user->id, fakeAssertion(random_bytes(16)), $session);

    expect($result)->toBeFalse();
});

it('verifyAndRelease returns false when the assertion rawId decodes under no base64 variant', function (): void {
    $user = biometricUser('verify-garbagerawid');

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $session->put(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    // Invalid in both base64 alphabets, so the normaliser exhausts every
    // variant and returns ''.
    $result = $service->verifyAndRelease($user->id, ['rawId' => '@@@@'], $session);

    expect($result)->toBeFalse();
});

it('verifyAndRelease returns false when the assertion omits a rawId entirely', function (): void {
    $user = biometricUser('verify-norawid');

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $session->put(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    // No rawId key -> normaliseAssertionRawId(null) short-circuits to ''.
    $result = $service->verifyAndRelease($user->id, ['type' => 'public-key'], $session);

    expect($result)->toBeFalse();
});

it('unwrapStoredDataKey round-trips a genuine blob and rejects a corrupt one', function (): void {
    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var AppLockKeyWrap $keyWrap */
    $keyWrap = $this->app->make(AppLockKeyWrap::class);

    $dataKey = random_bytes(32);
    $secret = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrappedBytes = base64_decode($keyWrap->wrap($dataKey, $secret), strict: true);
    expect($wrappedBytes)->not->toBeFalse();

    // Blob layout is secret(32B) || wrapped key, and the test shield makes
    // reveal() a no-op, so the stored bytes are the blob itself.
    $goodRow = (object) ['biometric_wrap_secret' => $secret.$wrappedBytes];

    $reflected = new ReflectionMethod(WebAuthnBiometricService::class, 'unwrapStoredDataKey');
    /** @var string|null $unwrapped */
    $unwrapped = $reflected->invoke($service, $goodRow);
    expect($unwrapped)->toBe($dataKey);

    $missingRow = (object) ['biometric_wrap_secret' => null];
    expect($reflected->invoke($service, $missingRow))->toBeNull();

    // A blob shorter than the secret length can hold no wrapped key -> null.
    $shortRow = (object) ['biometric_wrap_secret' => str_repeat("\x00", 8)];
    expect($reflected->invoke($service, $shortRow))->toBeNull();

    sodium_memzero($dataKey);
    sodium_memzero($secret);
});

it('extractDataKey returns null when the wrapped bytes are corrupt', function (): void {
    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);

    // The right length, but the payload is not a valid wrap.
    $blob = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES + 48);

    $reflected = new ReflectionMethod(WebAuthnBiometricService::class, 'extractDataKey');
    expect($reflected->invoke($service, $blob))->toBeNull();
});

it('releaseDataKey advances the counter, resets failures, and unlocks the session', function (): void {
    $user = biometricUser('release-datakey');

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var AppLockKeyWrap $keyWrap */
    $keyWrap = $this->app->make(AppLockKeyWrap::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // Store a credential whose wrap-secret blob genuinely unwraps to $dataKey.
    $dataKey = random_bytes(32);
    $secret = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrappedBytes = base64_decode($keyWrap->wrap($dataKey, $secret), strict: true);
    $rawId = random_bytes(16);
    $store->store($user->id, base64_encode($rawId), 'Release Device', $secret.$wrappedBytes, 'fake-cbor', 'webauthn');

    // Driven up first, so the reset is provable rather than vacuous.
    $credRow = $store->findByCredentialId($user->id, base64_encode($rawId));
    /** @var stdClass $credRow */
    $store->incrementFailureCount($credRow->id);

    $lockState->lock($session);

    $reflected = new ReflectionMethod(WebAuthnBiometricService::class, 'releaseDataKey');
    $released = $reflected->invoke(
        $service,
        $credRow,
        credentialRecordWithCounter($rawId, 42),
        $session,
    );

    expect($released)->toBeTrue();

    expect($keyService->release($session))->toBe($dataKey);

    $after = $store->findByCredentialId($user->id, base64_encode($rawId));
    /** @var stdClass $after */
    expect((int) $after->counter)->toBe(42);
    expect((int) $after->biometric_failed_count)->toBe(0);

    sodium_memzero($secret);
});

it('releaseDataKey returns false when the credential row has no usable id', function (): void {
    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $reflected = new ReflectionMethod(WebAuthnBiometricService::class, 'releaseDataKey');

    $released = $reflected->invoke(
        $service,
        (object) ['id' => null, 'biometric_wrap_secret' => 'irrelevant'],
        credentialRecordWithCounter(random_bytes(16), 1),
        $session,
    );

    expect($released)->toBeFalse();
});
