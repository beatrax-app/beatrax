<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Desktop\Internal\Native\SafeStorageBackendProbe;
use Modules\Desktop\Internal\Native\SafeStorageSecretShield;
use Modules\Desktop\Tests\Support\StubElectronApi;
use Native\Desktop\System;

function shieldBackend(string $backend): SafeStorageBackendProbe
{
    return new SafeStorageBackendProbe(
        new StubElectronApi((string) json_encode(['result' => $backend])),
        'Linux',
    );
}

function offBundleShield(): SafeStorageSecretShield
{
    // canEncrypt() is false off-bundle, so the shield is the identity function —
    // which is what keeps every OAuth and biometric test green without a running
    // desktop shell. The System mock is never invoked on this path.
    $config = new Repository(['nativephp-internal' => ['running' => false]]);

    return new SafeStorageSecretShield(
        new DesktopKeyCustodian($config, Mockery::mock(System::class), shieldBackend('gnome_libsecret')),
    );
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

// The shield degrades to the identity function whenever Electron's safeStorage
// is unreachable — a Linux desktop with no keyring, a broken IPC hop. A
// hardcoded "yes I protect" would re-open the biometric-blob hole on exactly
// those machines, so the answer is probed from the bytes.
it('reports no at-rest protection when safeStorage is unavailable', function (): void {
    expect(offBundleShield()->protectsAtRest())->toBeFalse();
});

// The case that made this class necessary and then outgrew it. Inside a bundle
// on a Linux desktop with no keyring, safeStorage answers, encrypts, and
// round-trips: the byte probe alone says "protected" about ciphertext whose
// key is a password published in Chromium's source. Biometric enrolment writes
// a wrap of the app-lock data key on that answer.

function bundledShieldOn(string $backend): SafeStorageSecretShield
{
    $system = Mockery::mock(System::class);
    $system->shouldReceive('canEncrypt')->andReturn(true);
    $system->shouldReceive('encrypt')->andReturnUsing(static fn (string $value): string => 'cipher:'.$value);

    return new SafeStorageSecretShield(
        new DesktopKeyCustodian(
            new Repository(['nativephp-internal' => ['running' => true]]),
            $system,
            shieldBackend($backend),
        ),
    );
}

it('reports at-rest protection on a keyring-backed desktop', function (): void {
    expect(bundledShieldOn('gnome_libsecret')->protectsAtRest())->toBeTrue();
});

it('reports no at-rest protection on a Linux desktop with no keyring, though the bytes do change', function (): void {
    $shield = bundledShieldOn('basic_text');
    $blob = random_bytes(32);

    expect($shield->protectsAtRest())->toBeFalse()
        ->and($shield->protect($blob))->not->toBe($blob);
});
