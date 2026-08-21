<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

it('PinVerificationService class exists (RED until 05-02)', function (): void {
    expect(class_exists(PinVerificationService::class))->toBeTrue();
});

it('correct PIN returns the unwrapped data key and resets failed_attempts', function (): void {
    expect(class_exists(PinVerificationService::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // Locked first, so the unlock is provable rather than assumed.
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    $lockState->lock($session);
    expect($lockState->isLocked($session))->toBeTrue();

    $dataKey = $service->verify($user->id, '123456', $session);

    expect($dataKey)->toBeString()
        ->and(strlen($dataKey))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    expect($lockState->isLocked($session))->toBeFalse();

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect((int) $row->failed_attempts)->toBe(0);
    expect($row->locked_until)->toBeNull();
});

it('wrong PIN increments failed_attempts in user_app_lock_configs', function (): void {
    expect(class_exists(PinVerificationService::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'bob',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $result = $service->verify($user->id, '000000', $session);
    expect($result)->toBeNull();

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect((int) $row->failed_attempts)->toBe(1);

    $service->verify($user->id, '000000', $session);

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect((int) $row->failed_attempts)->toBe(2);
    expect($row->locked_until)->toBeNull();
});

it('reaching the backoff threshold sets locked_until and blocks further attempts', function (): void {
    expect(class_exists(PinVerificationService::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'charlie',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // BACKOFF_THRESHOLD wrong PINs.
    for ($i = 0; $i < 5; $i++) {
        $service->verify($user->id, '000000', $session);
    }

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect((int) $row->failed_attempts)->toBe(5);
    expect($row->locked_until)->not->toBeNull('locked_until should be set after BACKOFF_THRESHOLD failures');

    $result = $service->verify($user->id, '123456', $session);
    expect($result)->toBeNull('Correct PIN should still return null while locked_until is in the future');
});

it('successful PIN unlock re-arms disarmed biometric credentials (D-16 / WR-01)', function (): void {
    $user = User::query()->create([
        'username' => 'rearm-user',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    $store->store($user->id, base64_encode('rearm-cred'), 'Rearm Device', str_repeat("\xEE", 32), 'fake-cbor', 'webauthn');

    $cred = $store->findByCredentialId($user->id, base64_encode('rearm-cred'));
    /** @var stdClass $cred */
    for ($i = 0; $i < BiometricDeviceStore::BIOMETRIC_DISABLE_THRESHOLD; $i++) {
        $store->incrementFailureCount($cred->id);
    }
    $disarmed = $store->findByCredentialId($user->id, base64_encode('rearm-cred'));
    /** @var stdClass $disarmed */
    expect($store->isArmed($disarmed))->toBeFalse();

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $dataKey = $service->verify($user->id, '123456', $session);
    expect($dataKey)->toBeString();

    $rearmed = $store->findByCredentialId($user->id, base64_encode('rearm-cred'));
    /** @var stdClass $rearmed */
    expect($store->isArmed($rearmed))->toBeTrue();
    expect((int) $rearmed->biometric_failed_count)->toBe(0);
});

it('reaching the failure cap signs the session out', function (): void {
    expect(class_exists(PinVerificationService::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'dave',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // Wound up in the database rather than through the service: nine real
    // attempts would pay the Argon2id cost nine times.
    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['failed_attempts' => 9]); // one below HARD_CAP=10

    $result = $service->verify($user->id, '000000', $session);
    expect($result)->toBeNull();

    $alert = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'auth.lock.hard_cap_reached')
        ->first();

    expect($alert)->not->toBeNull('hard_cap_reached SystemAlert should be emitted');
    expect($alert->severity)->toBe('critical');

    expect($this->app->make(AuthManager::class)->guard()->check())
        ->toBeFalse('Session should be signed out after hard cap');
});
