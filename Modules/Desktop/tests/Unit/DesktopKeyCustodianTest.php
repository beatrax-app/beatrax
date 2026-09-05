<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Auth\Public\Enums\KeyCustody;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Desktop\Internal\Native\SafeStorageBackendProbe;
use Modules\Desktop\Tests\Support\StubElectronApi;
use Native\Desktop\System;

// Linux is the only platform whose safeStorage has a mode that encrypts under
// a password anyone can look up, so it is the only one the backend has to be
// asked about -- and the one every case below names explicitly.
function custodianBackend(string $backend): SafeStorageBackendProbe
{
    return new SafeStorageBackendProbe(
        new StubElectronApi((string) json_encode(['result' => $backend])),
        'Linux',
    );
}

function offBundleCustodian(): DesktopKeyCustodian
{
    // canEncrypt() short-circuits before any System call when running=false, so a
    // never-invoked mock is enough. The real safeStorage round-trip is only
    // reachable on-bundle.
    return new DesktopKeyCustodian(
        new Repository(['nativephp-internal' => ['running' => false]]),
        Mockery::mock(System::class),
        custodianBackend('gnome_libsecret'),
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

    $handle = (new DesktopKeyCustodian(bundleConfig(), $ready, custodianBackend('gnome_libsecret')))->store($raw);

    $racing = Mockery::mock(System::class);
    $racing->shouldReceive('canEncrypt')->andReturn(false);
    $racing->shouldNotReceive('decrypt');

    $released = (new DesktopKeyCustodian(bundleConfig(), $racing, custodianBackend('gnome_libsecret')))->read($handle);

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

    $custodian = new DesktopKeyCustodian(bundleConfig(), $system, custodianBackend('gnome_libsecret'));

    expect($custodian->read($custodian->store($raw)))->toBe($raw);
});

// "Wired" has to mean the key reached a store that protects it. On a Linux
// desktop with no keyring safeStorage still answers, still encrypts and still
// round-trips -- under a password published in Chromium's own source -- so the
// custody report is the only thing that can tell the two apart.

function bundledCustodianOn(string $backend): DesktopKeyCustodian
{
    $system = Mockery::mock(System::class);
    $system->shouldReceive('canEncrypt')->andReturn(true);
    $system->shouldReceive('encrypt')->andReturnUsing(static fn (string $value): string => 'cipher:'.$value);

    return new DesktopKeyCustodian(bundleConfig(), $system, custodianBackend($backend));
}

it('reports session custody off the bundle, where no store applies', function (): void {
    expect(offBundleCustodian()->custody())->toBe(KeyCustody::Session)
        ->and(offBundleCustodian()->custody()->protectsAtRest())->toBeFalse();
});

it('reports session custody inside the bundle while safeStorage is still down', function (): void {
    $system = Mockery::mock(System::class);
    $system->shouldReceive('canEncrypt')->andReturn(false);

    $custodian = new DesktopKeyCustodian(bundleConfig(), $system, custodianBackend('gnome_libsecret'));

    expect($custodian->custody())->toBe(KeyCustody::Session);
});

it('reports operating-system custody on a keyring-backed desktop', function (): void {
    $custody = bundledCustodianOn('gnome_libsecret')->custody();

    expect($custody)->toBe(KeyCustody::OperatingSystem)
        ->and($custody->protectsAtRest())->toBeTrue();
});

it('refuses to call a keyring-less Linux desktop protected', function (): void {
    $custody = bundledCustodianOn('basic_text')->custody();

    expect($custody)->toBe(KeyCustody::PlatformStoreDoesNotProtect)
        ->and($custody->protectsAtRest())->toBeFalse();
});

// Storage stays on safeStorage there on purpose. Refusing to encrypt would
// strand every blob an earlier build wrote on that machine -- the OAuth
// secrets and the biometric wrap among them -- for a layer that was never the
// secret. What changes is the claim, not the bytes.
it('still encrypts on a keyring-less Linux desktop rather than stranding what it wrote', function (): void {
    $raw = random_bytes(32);
    $handle = bundledCustodianOn('basic_text')->store($raw);

    expect($handle)->not->toBe($raw)
        ->and($handle)->toStartWith('nativephp:safestorage:v1:');
});

// The upgrade path, for a reader whose session predates custody entirely and
// holds the raw key under no marker. The bundle cannot tell that from a
// ciphertext this machine can no longer open, so it answers null and the lock
// screen asks for the PIN once. The durable wraps in user_app_lock_configs are
// untouched by any of this, so that PIN still opens the ledger.
it('sends a pre-custody session to one PIN unlock rather than releasing the raw key as a handle', function (): void {
    $raw = random_bytes(32);

    $system = Mockery::mock(System::class);
    $system->shouldReceive('canEncrypt')->andReturn(true);
    $system->shouldReceive('decrypt')->andReturnUsing(
        static fn (string $value): ?string => str_starts_with($value, 'cipher:') ? substr($value, 7) : null,
    );

    $custodian = new DesktopKeyCustodian(bundleConfig(), $system, custodianBackend('gnome_libsecret'));

    expect($custodian->read($raw))->toBeNull();
});
