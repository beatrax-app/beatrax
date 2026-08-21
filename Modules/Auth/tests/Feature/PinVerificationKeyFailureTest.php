<?php

declare(strict_types=1);

// A correct PIN over missing or corrupt wrap material must fail closed and
// alert, never release a garbage data key.

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

    // The PIN hash stays valid, so the PIN verifies and the unwrap is what
    // finds nothing to work with.
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

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update([
            // A correctly sized salt, so the derivation succeeds and it is the
            // unwrap of the garbage bytes that fails.
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
