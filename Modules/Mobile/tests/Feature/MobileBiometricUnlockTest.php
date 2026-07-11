<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Internal\Identity\BiometricUnlockBridge;

uses(RefreshDatabase::class);

/*
 * MOBILE-01 (R6) — BiometricUnlockBridge + MobileLockScreen (15-06-PLAN.md).
 *
 * Task 1 pins the bridge's bool-only, never-fatal-without-the-native-facade
 * contract. Task 2 extends this file to prove the full lock-screen wiring:
 * biometric success releases the LOCK-04 key + redirects (T-15-14), abort
 * never releases the key (T-15-15), and the PIN fallback still works
 * unchanged.
 *
 * `Native\Mobile\Facades\Biometrics` is installed ONLY under mobile-app/
 * vendor (Plan 03) — unreachable from this repo-root toolchain (15-06-PLAN.md
 * environment notes). `BiometricUnlockBridge::isAvailable()` is therefore
 * asserted against ITS OWN guard behavior (always false here, since the
 * facade class cannot resolve).
 */

it('BiometricUnlockBridge isAvailable() returns false without the native facade — never fatal in tests/web', function (): void {
    $bridge = new BiometricUnlockBridge;

    expect($bridge->isAvailable())->toBeFalse();
});

it('BiometricUnlockBridge prompt() returns false when unavailable — bool-only, never touches the native facade', function (): void {
    $bridge = new BiometricUnlockBridge;

    expect($bridge->prompt())->toBeFalse();
    expect($bridge->prompt('Custom reason'))->toBeFalse();
});
