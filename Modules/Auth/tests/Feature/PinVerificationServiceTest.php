<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-02

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

/*
 * Feature coverage for PinVerificationService: correct PIN returns the
 * unwrapped data key and resets failed_attempts; wrong PIN increments
 * the counter with escalating backoff; reaching the cap signs the session out.
 *
 * These tests go GREEN when plan 05-02 creates PinVerificationService.
 */

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

    // Mark the session as locked first so we can verify unlock() is called.
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    $lockState->lock($session);
    expect($lockState->isLocked($session))->toBeTrue();

    $dataKey = $service->verify($user->id, '123456', $session);

    expect($dataKey)->toBeString()
        ->and(strlen($dataKey))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    // Session should now be unlocked.
    expect($lockState->isLocked($session))->toBeFalse();

    // failed_attempts should be reset to 0.
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

    // Wrong PIN — does not throw, returns null.
    $result = $service->verify($user->id, '000000', $session);
    expect($result)->toBeNull();

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect((int) $row->failed_attempts)->toBe(1);

    // Second wrong attempt.
    $service->verify($user->id, '000000', $session);

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect((int) $row->failed_attempts)->toBe(2);
    // Below threshold, locked_until should still be null.
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

    // Accumulate 5 wrong PINs (BACKOFF_THRESHOLD).
    for ($i = 0; $i < 5; $i++) {
        $service->verify($user->id, '000000', $session);
    }

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect((int) $row->failed_attempts)->toBe(5);
    expect($row->locked_until)->not->toBeNull('locked_until should be set after BACKOFF_THRESHOLD failures');

    // Now even a correct PIN should return null (backoff active).
    $result = $service->verify($user->id, '123456', $session);
    expect($result)->toBeNull('Correct PIN should still return null while locked_until is in the future');
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

    // Wind the failed_attempts counter up to HARD_CAP - 1 directly in DB to avoid
    // Argon2id latency on every iteration (only the final cap attempt runs the service).
    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['failed_attempts' => 9]); // one below HARD_CAP=10

    // The 10th wrong attempt triggers the hard cap.
    $result = $service->verify($user->id, '000000', $session);
    expect($result)->toBeNull();

    // A SystemAlert audit row must have been emitted.
    $alert = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'auth.lock.hard_cap_reached')
        ->first();

    expect($alert)->not->toBeNull('hard_cap_reached SystemAlert should be emitted');
    expect($alert->severity)->toBe('critical');

    // Auth guard should have been signed out.
    expect($this->app->make(AuthManager::class)->guard()->check())
        ->toBeFalse('Session should be signed out after hard cap');
});
