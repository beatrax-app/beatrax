<?php

declare(strict_types=1);

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;

class CountingSecureStorageCustodian extends SecureStorageKeyCustodian
{
    /** @var array<string, string> */
    public array $slots = [];

    public int $nativeReads = 0;

    protected function runtimeAvailable(): bool
    {
        return true;
    }

    protected function nativeSet(string $key, string $value): bool
    {
        $this->slots[$key] = $value;

        return true;
    }

    protected function nativeGet(string $key): ?string
    {
        $this->nativeReads++;

        return $this->slots[$key] ?? null;
    }

    protected function nativeDelete(string $key): void
    {
        unset($this->slots[$key]);
    }
}

function countingCustodianUser(int $id): CurrentUser
{
    $user = Mockery::mock(CurrentUser::class);
    $user->shouldReceive('id')->andReturn($id);

    return $user;
}

// SensitiveColumnCodec resolves the KEK once per decrypted value, reaching
// AppLockKeyService::release() before GdkKeyringService can consult its own memo,
// which is keyed on a fingerprint of the KEK. On device each one is a Keystore
// round trip through JNI, so a page of ~140 rows took 1.2-3.4s to render.

it('asks the native store once however many values are decrypted', function (): void {
    $custodian = new CountingSecureStorageCustodian(countingCustodianUser(1));
    $handle = $custodian->store(str_repeat("\x2a", 32));

    // store() already knows the key, so even the first read is free.
    $custodian->nativeReads = 0;

    for ($i = 0; $i < 140; $i++) {
        expect($custodian->read($handle))->toBe(str_repeat("\x2a", 32));
    }

    expect($custodian->nativeReads)->toBeLessThanOrEqual(1);
});

it('goes back to the native store after the key is forgotten', function (): void {
    // Locking calls forget(); a cached key that survived it would survive the
    // lock too, and this instance is a singleton in a persistent runtime.
    $custodian = new CountingSecureStorageCustodian(countingCustodianUser(1));
    $handle = $custodian->store(str_repeat("\x2a", 32));

    $custodian->forget($handle);

    expect($custodian->read($handle))->toBeNull();
});

it('re-reads a slot whose entry could not be decoded rather than caching the failure', function (): void {
    $custodian = new CountingSecureStorageCustodian(countingCustodianUser(1));
    $handle = $custodian->store(str_repeat("\x2a", 32));
    $custodian->forget($handle);

    // Not valid base64 — read() must return null without remembering it, so a
    // repaired entry is picked up rather than staying broken for the process.
    $custodian->slots[$handle] = '!!!not-base64!!!';
    $custodian->nativeReads = 0;

    expect($custodian->read($handle))->toBeNull()
        ->and($custodian->read($handle))->toBeNull()
        ->and($custodian->nativeReads)->toBe(2);
});
