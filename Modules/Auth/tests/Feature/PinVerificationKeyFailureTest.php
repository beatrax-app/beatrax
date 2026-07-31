<?php

declare(strict_types=1);

// Coverage for the PIN-unlock key-integrity branches extracted into
// PinVerificationService::unwrapDataKey() and currentFailedAttempts() during
// the Auth Sonar refactor: a correct PIN whose stored wrap material is
// missing or corrupt must fail closed (return null) AND raise a critical
// SystemAlert, rather than releasing a garbage data key.

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

function pinLockUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
}

it('verify returns null and alerts when the PIN wrap material is missing', function (): void {
    $user = pinLockUser('pin-missing-wrap');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    // Wipe the wrap material while keeping the (valid) PIN hash, so the PIN
    // verifies but unwrapDataKey() finds no key blob to unwrap.
    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['kdf_salt' => null, 'pin_wrapped_key' => null]);

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $result = $service->verify($user->id, '123456', $session);

    expect($result)->toBeNull();

    $alert = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'auth.lock.corrupted_key')
        ->first();

    expect($alert)->not->toBeNull('a corrupted_key SystemAlert should be emitted');
    expect($alert->severity)->toBe('critical');
});

it('verify returns null and alerts when the wrapped key blob is corrupt', function (): void {
    $user = pinLockUser('pin-corrupt-wrap');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    // Keep a plausible (string) salt + wrapped key, but the wrapped bytes are
    // garbage, so keyWrap->unwrap() returns false past the type guard.
    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update([
            // A correctly-sized KDF salt so deriveWrapKey() succeeds, but the
            // wrapped-key bytes are garbage, so keyWrap->unwrap() returns false.
            'kdf_salt' => random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES),
            'pin_wrapped_key' => base64_encode(random_bytes(60)),
        ]);

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $result = $service->verify($user->id, '123456', $session);

    expect($result)->toBeNull();

    $alert = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'auth.lock.corrupted_key')
        ->first();

    expect($alert)->not->toBeNull('a corrupted_key SystemAlert should be emitted');
    expect($alert->severity)->toBe('critical');
});
