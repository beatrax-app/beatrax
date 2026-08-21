<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;

// The validator is never mocked: it needs a real CBOR public key, which only
// the browser can produce. So the wrap/release cycle is driven through the
// store and the key-wrap helpers instead.

it('WebAuthnBiometricService class exists (RED until 05-05)', function (): void {
    expect(class_exists(WebAuthnBiometricService::class))->toBeTrue();
});

it('creationOptions returns a JSON-serializable structure with expected keys', function (): void {
    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);

    $user = User::query()->create([
        'username' => 'wa-alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $options = $service->creationOptions($user->id, $user->username, $session);

    expect($options)->toBeArray();
    expect($options)->toHaveKey('challenge');
    expect($options)->toHaveKey('rp');
    expect($options)->toHaveKey('user');
    expect($options)->toHaveKey('pubKeyCredParams');

    expect($session->has(WebAuthnBiometricService::CREATION_CHALLENGE_SESSION))->toBeTrue();
});

it('requestOptions lists only armed credentials', function (): void {
    $user = User::query()->create([
        'username' => 'wa-bob',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $store->store($user->id, base64_encode('cred-armed'), 'Armed Device', str_repeat("\xAA", 32), 'fake-cbor', 'webauthn');
    $store->store($user->id, base64_encode('cred-disarmed'), 'Disarmed Device', str_repeat("\xBB", 32), 'fake-cbor', 'webauthn');

    $disarmed = $store->findByCredentialId($user->id, base64_encode('cred-disarmed'));
    /** @var stdClass $disarmed */
    for ($i = 0; $i < BiometricDeviceStore::BIOMETRIC_DISABLE_THRESHOLD; $i++) {
        $store->incrementFailureCount($disarmed->id);
    }

    $options = $service->requestOptions($user->id, $session);

    expect($options)->toBeArray();
    expect($options)->toHaveKey('challenge');

    $allowCredentials = $options['allowCredentials'] ?? [];
    /** @var array<int, array<string, mixed>> $allowCredentials */
    expect($allowCredentials)->toHaveCount(1);

    expect($session->has(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION))->toBeTrue();
});

it('wrap and unwrap via AppLockKeyWrap round-trips the data key', function (): void {
    /** @var AppLockKeyWrap $keyWrap */
    $keyWrap = $this->app->make(AppLockKeyWrap::class);

    $dataKey = random_bytes(32);
    $secret = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    $wrapped = $keyWrap->wrap($dataKey, $secret);
    $unwrapped = $keyWrap->unwrap($wrapped, $secret);

    expect($unwrapped)->toBe($dataKey);
    sodium_memzero($dataKey);
    sodium_memzero($secret);
});

it('verifyAndRelease returns false when no challenge is in the session', function (): void {
    $user = User::query()->create([
        'username' => 'wa-carol',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $result = $service->verifyAndRelease($user->id, ['rawId' => ''], $session);

    expect($result)->toBeFalse();
});

it('verifyAndRelease returns false when the credential is disarmed', function (): void {
    $user = User::query()->create([
        'username' => 'wa-dave',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $credentialId = base64_encode('cred-dave-disarmed');
    $store->store($user->id, $credentialId, 'Dave Device', str_repeat("\xCC", 32), 'fake-cbor', 'webauthn');

    $cred = $store->findByCredentialId($user->id, $credentialId);
    /** @var stdClass $cred */
    for ($i = 0; $i < BiometricDeviceStore::BIOMETRIC_DISABLE_THRESHOLD; $i++) {
        $store->incrementFailureCount($cred->id);
    }

    // A valid challenge, so the disarm check is what rejects this and not the
    // missing-challenge branch.
    $session->put(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    $result = $service->verifyAndRelease($user->id, ['rawId' => $credentialId], $session);

    expect($result)->toBeFalse();
});

it('verifyAndRelease increments biometric_failed_count when validation throws on a base64url rawId', function (): void {
    $user = User::query()->create([
        'username' => 'wa-frank',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    /** @var WebAuthnBiometricService $service */
    $service = $this->app->make(WebAuthnBiometricService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $rawCredentialBytes = random_bytes(16);
    $credentialId = base64_encode($rawCredentialBytes);
    $store->store($user->id, $credentialId, 'Frank Device', str_repeat("\xDD", 32), 'fake-cbor', 'webauthn');

    $session->put(WebAuthnBiometricService::REQUEST_CHALLENGE_SESSION, base64_encode(random_bytes(32)));

    // Enrollment stores credential_id in standard base64, but lock.js sends the
    // rawId base64url-encoded without padding — the mismatch under test.
    $rawIdBase64url = sodium_bin2base64($rawCredentialBytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    // Structurally plausible, payload garbage: validation throws.
    $result = $service->verifyAndRelease($user->id, [
        'id' => $rawIdBase64url,
        'rawId' => $rawIdBase64url,
        'type' => 'public-key',
        'response' => [
            'authenticatorData' => 'Z2FyYmFnZQ',
            'clientDataJSON' => 'Z2FyYmFnZQ',
            'signature' => 'Z2FyYmFnZQ',
            'userHandle' => null,
        ],
    ], $session);

    expect($result)->toBeFalse();

    // The throttle must still engage despite the encoding mismatch.
    $cred = $store->findByCredentialId($user->id, $credentialId);
    expect($cred)->not->toBeNull();
    /** @var stdClass $cred */
    expect((int) $cred->biometric_failed_count)->toBe(1);
});

it('LockStateManager unlock then AppLockKeyService release returns the data key', function (): void {
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);

    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $dataKey = random_bytes(32);

    $lockState->lock($session);
    expect($keyService->release($session))->toBeNull();

    $lockState->unlock($session, $dataKey);
    $released = $keyService->release($session);

    expect($released)->toBe($dataKey);
    sodium_memzero($dataKey);
});

it('AppLockKeyService release returns null after the session is locked again', function (): void {
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);

    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $dataKey = random_bytes(32);

    $lockState->unlock($session, $dataKey);
    expect($keyService->release($session))->not->toBeNull();

    $lockState->lock($session);
    expect($keyService->release($session))->toBeNull();
    sodium_memzero($dataKey);
});
