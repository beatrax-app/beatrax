<?php

declare(strict_types=1);

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Exceptions\SecureStorageException;
use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;

class FakeSecureStorageCustodian extends SecureStorageKeyCustodian
{
    /** @var array<string, string> */
    public array $slots = [];

    public bool $setSucceeds = true;

    protected function runtimeAvailable(): bool
    {
        return true;
    }

    protected function nativeSet(string $key, string $value): bool
    {
        if (! $this->setSucceeds) {
            return false;
        }
        $this->slots[$key] = $value;

        return true;
    }

    protected function nativeGet(string $key): ?string
    {
        return $this->slots[$key] ?? null;
    }

    protected function nativeDelete(string $key): void
    {
        unset($this->slots[$key]);
    }
}

function secureStorageCurrentUser(int $id): CurrentUser
{
    $user = Mockery::mock(CurrentUser::class);
    $user->shouldReceive('id')->andReturn($id);

    return $user;
}

// In the repo-root toolchain the SecureStorage facade is absent and
// runtimeAvailable() is false, so the custodian is the identity function, which is
// what keeps every unrelated test green without the plugin. The on-device paths
// run against an in-memory subclass; the real Keychain is on-device verification.

it('degrades store() to pass-through when the mobile runtime is unavailable', function (): void {
    $custodian = new SecureStorageKeyCustodian(secureStorageCurrentUser(1));
    $raw = str_repeat("\x2a", 32);

    expect($custodian->store($raw))->toBe($raw);
});

it('round-trips a key through store()/read() on the fallback path', function (): void {
    $custodian = new SecureStorageKeyCustodian(secureStorageCurrentUser(1));
    $raw = random_bytes(32);

    expect($custodian->read($custodian->store($raw)))->toBe($raw);
});

it('forget() is a safe no-op off-device', function (): void {
    $custodian = new SecureStorageKeyCustodian(secureStorageCurrentUser(1));

    expect(fn () => $custodian->forget('beatrax.session.data_key.1'))->not->toThrow(Throwable::class);
});

it('stores the key under a per-user slot and returns the slot name as the handle', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $raw = random_bytes(32);

    $handle = $custodian->store($raw);

    expect($handle)->toBe('beatrax.session.data_key.7')
        ->and($custodian->slots)->toHaveKey('beatrax.session.data_key.7')
        ->and($custodian->slots['beatrax.session.data_key.7'])->toBe(base64_encode($raw))
        ->and($custodian->slots['beatrax.session.data_key.7'])->not->toBe($raw);
});

it('round-trips the key through the native store on-device', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $raw = random_bytes(32);

    expect($custodian->read($custodian->store($raw)))->toBe($raw);
});

it('scopes the slot per user so two users never collide', function (): void {
    $a = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $b = new FakeSecureStorageCustodian(secureStorageCurrentUser(9));

    expect($a->store(random_bytes(32)))->toBe('beatrax.session.data_key.7')
        ->and($b->store(random_bytes(32)))->toBe('beatrax.session.data_key.9');
});

it('fails closed by throwing instead of returning the raw key when native set() fails', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $custodian->setSucceeds = false;
    $raw = random_bytes(32);

    // A native-set failure on-device must never degrade to a plaintext key in
    // the session (SESSION_DRIVER=database would persist it), so store() throws
    // rather than handing back the raw key as its handle — and writes nothing.
    expect(fn () => $custodian->store($raw))->toThrow(SecureStorageException::class);
    expect($custodian->slots)->not->toHaveKey('beatrax.session.data_key.7');
});

it('returns null (not the slot name) when the entry is missing/evicted', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));

    // A slot-shaped handle whose entry was never written / was evicted.
    expect($custodian->read('beatrax.session.data_key.7'))->toBeNull();
});

it('returns null (not garbage) when the stored value is not valid base64', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $custodian->slots['beatrax.session.data_key.7'] = 'not+valid+base64+!!!';

    expect($custodian->read('beatrax.session.data_key.7'))->toBeNull();
});

it('forget() deletes the native slot', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $handle = $custodian->store(random_bytes(32));
    expect($custodian->slots)->toHaveKey($handle);

    $custodian->forget($handle);

    expect($custodian->slots)->not->toHaveKey($handle)
        ->and($custodian->read($handle))->toBeNull();
});
