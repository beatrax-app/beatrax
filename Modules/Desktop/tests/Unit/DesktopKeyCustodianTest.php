<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Native\Desktop\System;

/*
 * Unit tests for DesktopKeyCustodian's OFF-BUNDLE fallback.
 *
 * canEncrypt() short-circuits to false unless `nativephp-internal.running` is
 * true, so in the repo toolchain store()/read() are the identity function and
 * never touch the Electron safeStorage facade. The canEncrypt()==true path
 * (real base64+encrypt round-trip, and the null-on-decrypt-failure guard) is
 * exercised only by on-bundle UAT — safeStorage cannot be driven headless.
 */

function offBundleCustodian(): DesktopKeyCustodian
{
    // running=false → canEncrypt() short-circuits before any System call, so a
    // never-invoked mock is enough (safeStorage is unreachable headless).
    return new DesktopKeyCustodian(
        new Repository(['nativephp-internal' => ['running' => false]]),
        Mockery::mock(System::class),
    );
}

it('store() returns the raw key unchanged when safeStorage is unavailable', function (): void {
    $raw = str_repeat("\x2a", 32);

    expect(offBundleCustodian()->store($raw))->toBe($raw);
});

it('read() returns the blob unchanged (the raw key) on the fallback path', function (): void {
    $custodian = offBundleCustodian();
    $raw = random_bytes(32);

    // Fallback: store() returned the raw key as the handle, read() hands it back.
    expect($custodian->read($custodian->store($raw)))->toBe($raw);
});

it('forget() is a safe no-op and does not throw', function (): void {
    expect(fn () => offBundleCustodian()->forget('any-handle'))->not->toThrow(Throwable::class);
});
