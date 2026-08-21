<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Desktop\Internal\Native\SafeStorageSecretShield;
use Native\Desktop\System;

function offBundleShield(): SafeStorageSecretShield
{
    // canEncrypt() is false off-bundle, so the shield is the identity function —
    // which is what keeps every OAuth and biometric test green without a running
    // desktop shell. The System mock is never invoked on this path.
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
