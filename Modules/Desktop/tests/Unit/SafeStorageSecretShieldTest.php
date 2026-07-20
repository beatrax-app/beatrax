<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Desktop\Internal\Native\SafeStorageSecretShield;
use Native\Desktop\System;

/*
 * Unit tests for SafeStorageSecretShield.
 *
 * The shield delegates to DesktopKeyCustodian, which degrades to pass-through
 * when `nativephp-internal.running` is not true — i.e. everywhere the repo
 * test toolchain runs (no Electron safeStorage). So off-bundle the shield is
 * the identity function, which is exactly what keeps every OAuth / biometric
 * test green without a running desktop shell. The real safeStorage round-trip
 * is exercised only by on-device / on-bundle UAT.
 */

function offBundleShield(): SafeStorageSecretShield
{
    // running=false → DesktopKeyCustodian::canEncrypt() is false → identity;
    // the System mock is never invoked on this path.
    $config = new Repository(['nativephp-internal' => ['running' => false]]);

    return new SafeStorageSecretShield(new DesktopKeyCustodian($config, Mockery::mock(System::class)));
}

it('is identity in both directions when safeStorage is unavailable', function (): void {
    $shield = offBundleShield();
    $blob = random_bytes(80);

    expect($shield->protect($blob))->toBe($blob)
        ->and($shield->reveal($blob))->toBe($blob)
        ->and($shield->reveal($shield->protect($blob)))->toBe($blob);
});

it('reveals a never-shielded legacy value unchanged', function (): void {
    $shield = offBundleShield();

    expect($shield->reveal('legacy-oauth-secret'))->toBe('legacy-oauth-secret');
});
