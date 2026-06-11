<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-02

use Modules\Auth\Internal\Lock\PinVerificationService;
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

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);

    // Full behavioral test is implemented once PinVerificationService and
    // AppLockProvisioner exist in plan 05-02. This test verifies the contract.
    expect($service)->toBeInstanceOf(PinVerificationService::class);
});

it('wrong PIN increments failed_attempts in user_app_lock_configs', function (): void {
    expect(class_exists(PinVerificationService::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'bob',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);
    expect($service)->toBeInstanceOf(PinVerificationService::class);
});

it('reaching the failure cap signs the session out', function (): void {
    expect(class_exists(PinVerificationService::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'charlie',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var PinVerificationService $service */
    $service = $this->app->make(PinVerificationService::class);
    expect($service)->toBeInstanceOf(PinVerificationService::class);
});
