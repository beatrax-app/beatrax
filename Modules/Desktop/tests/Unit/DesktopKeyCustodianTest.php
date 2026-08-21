<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Native\Desktop\System;

function offBundleCustodian(): DesktopKeyCustodian
{
    // canEncrypt() short-circuits before any System call when running=false, so a
    // never-invoked mock is enough. The real safeStorage round-trip is only
    // reachable on-bundle.
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

    expect($custodian->read($custodian->store($raw)))->toBe($raw);
});

it('forget() is a safe no-op and does not throw', function (): void {
    expect(fn () => offBundleCustodian()->forget('any-handle'))->not->toThrow(Throwable::class);
});
