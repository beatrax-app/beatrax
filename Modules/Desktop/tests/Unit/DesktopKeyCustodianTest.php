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

// Inside the bundle safeStorage answers only once Electron has finished coming
// up, and every earlier request still reaches read(). A handle that came back
// unchanged there was passed on as the app-lock data key.

function bundleConfig(): Repository
{
    return new Repository(['nativephp-internal' => ['running' => true]]);
}

it('refuses to release the ciphertext as the data key while safeStorage is still unavailable', function (): void {
    $raw = random_bytes(32);

    $ready = Mockery::mock(System::class);
    $ready->shouldReceive('canEncrypt')->andReturn(true);
    $ready->shouldReceive('encrypt')->andReturnUsing(static fn (string $value): string => 'cipher:'.$value);

    $handle = (new DesktopKeyCustodian(bundleConfig(), $ready))->store($raw);

    $racing = Mockery::mock(System::class);
    $racing->shouldReceive('canEncrypt')->andReturn(false);
    $racing->shouldNotReceive('decrypt');

    $released = (new DesktopKeyCustodian(bundleConfig(), $racing))->read($handle);

    expect($released)->toBeNull()
        ->and($handle)->not->toBe($raw);
});

it('round-trips the data key once safeStorage answers', function (): void {
    $raw = random_bytes(32);

    $system = Mockery::mock(System::class);
    $system->shouldReceive('canEncrypt')->andReturn(true);
    $system->shouldReceive('encrypt')->andReturnUsing(static fn (string $value): string => 'cipher:'.$value);
    $system->shouldReceive('decrypt')->andReturnUsing(
        static fn (string $value): ?string => str_starts_with($value, 'cipher:') ? substr($value, 7) : null,
    );

    $custodian = new DesktopKeyCustodian(bundleConfig(), $system);

    expect($custodian->read($custodian->store($raw)))->toBe($raw);
});
