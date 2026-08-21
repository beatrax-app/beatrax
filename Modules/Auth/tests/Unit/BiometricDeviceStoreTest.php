<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\PlatformDetector;

// Pure logic only. The DB-backed store behaviour lives in
// BiometricEnrollmentTest, which has RefreshDatabase behind it.

it('BiometricDeviceStore class exists (RED until 05-05)', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();
});

it('exposes incrementFailureCount (DB behavior covered in BiometricEnrollmentTest)', function (): void {
    expect(method_exists(BiometricDeviceStore::class, 'incrementFailureCount'))->toBeTrue();
});

it('exposes resetFailureCount (DB behavior covered in BiometricEnrollmentTest)', function (): void {
    expect(method_exists(BiometricDeviceStore::class, 'resetFailureCount'))->toBeTrue();
});

it('isArmed() returns true when failure count is below the threshold', function (): void {
    /** @var BiometricDeviceStore $store */
    $store = app(BiometricDeviceStore::class);

    $credential = new stdClass;
    $credential->biometric_failed_count = 0;
    expect($store->isArmed($credential))->toBeTrue();

    $credential2 = new stdClass;
    $credential2->biometric_failed_count = BiometricDeviceStore::BIOMETRIC_DISABLE_THRESHOLD - 1;
    expect($store->isArmed($credential2))->toBeTrue();
});

it('isArmed() returns false when failure count is at or above the threshold', function (): void {
    /** @var BiometricDeviceStore $store */
    $store = app(BiometricDeviceStore::class);

    $credential = new stdClass;
    $credential->biometric_failed_count = BiometricDeviceStore::BIOMETRIC_DISABLE_THRESHOLD;
    expect($store->isArmed($credential))->toBeFalse();

    $credential2 = new stdClass;
    $credential2->biometric_failed_count = BiometricDeviceStore::BIOMETRIC_DISABLE_THRESHOLD + 1;
    expect($store->isArmed($credential2))->toBeFalse();
});

it('PlatformDetector returns "Use Touch ID" for macOS user-agent', function (): void {
    /** @var PlatformDetector $detector */
    $detector = app(PlatformDetector::class);

    $macOsUa = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    expect($detector->detectLabel($macOsUa))->toBe('Use Touch ID');
});

it('PlatformDetector returns "Use Windows Hello" for Windows user-agent', function (): void {
    /** @var PlatformDetector $detector */
    $detector = app(PlatformDetector::class);

    $windowsUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    expect($detector->detectLabel($windowsUa))->toBe('Use Windows Hello');
});

it('PlatformDetector returns "Use Face ID" for iOS user-agent', function (): void {
    /** @var PlatformDetector $detector */
    $detector = app(PlatformDetector::class);

    $iosUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    expect($detector->detectLabel($iosUa))->toBe('Use Face ID');
});

it('PlatformDetector returns "Use fingerprint" for Android/generic user-agent', function (): void {
    /** @var PlatformDetector $detector */
    $detector = app(PlatformDetector::class);

    $androidUa = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
    expect($detector->detectLabel($androidUa))->toBe('Use fingerprint');
});
