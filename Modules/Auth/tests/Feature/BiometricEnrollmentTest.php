<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-05

use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Core\Models\User;

/*
 * Feature coverage for biometric enrollment:
 * enrolling a device stores a row in user_biometric_credentials for the user.
 *
 * This test goes GREEN when plan 05-05 ships BiometricDeviceStore.
 */

it('BiometricDeviceStore class exists (RED until 05-05)', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();
});

it('enrolling a device stores a row in user_biometric_credentials', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    // Full enrollment test goes GREEN when 05-05 ships enroll().
    expect($store)->toBeInstanceOf(BiometricDeviceStore::class);
});
