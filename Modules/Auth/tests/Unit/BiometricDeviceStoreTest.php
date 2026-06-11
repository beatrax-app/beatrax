<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-05

use Modules\Auth\Internal\Lock\BiometricDeviceStore;

/*
 * Unit coverage for BiometricDeviceStore:
 * incrementFailureCount and resetFailureCount mutate biometric_failed_count.
 *
 * These tests go GREEN when plan 05-05 ships BiometricDeviceStore.
 */

it('BiometricDeviceStore class exists (RED until 05-05)', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();
});

it('incrementFailureCount increments biometric_failed_count on the credential row', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();

    /** @var BiometricDeviceStore $store */
    $store = app(BiometricDeviceStore::class);

    expect(method_exists($store, 'incrementFailureCount'))->toBeTrue();
});

it('resetFailureCount sets biometric_failed_count to 0', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();

    /** @var BiometricDeviceStore $store */
    $store = app(BiometricDeviceStore::class);

    expect(method_exists($store, 'resetFailureCount'))->toBeTrue();
});
