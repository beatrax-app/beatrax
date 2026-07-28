<?php

declare(strict_types=1);

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;

/*
 * Unit tests for SecureStorageKeyCustodian.
 *
 * Two layers:
 *   - OFF-DEVICE fallback: in the repo-root toolchain the SecureStorage facade
 *     is absent and NATIVEPHP_PLATFORM is unset, so runtimeAvailable() is false
 *     and the custodian is the identity function — what keeps every unrelated
 *     test green without the plugin.
 *   - ON-DEVICE: a subclass overrides the nativeSet/nativeGet/nativeDelete/
 *     runtimeAvailable seams with an in-memory Keychain so the per-user slot,
 *     the null-on-missing guard, and the base64-failure guard are all covered
 *     without the real facade (which is unreachable here). The real Keychain
 *     round-trip is on-device UAT.
 */

/**
 * In-memory stand-in for the native secure store, driving the on-device paths.
 */
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

// --- Off-device fallback (identity) -----------------------------------------

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

// --- On-device (fake Keychain) ----------------------------------------------

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

it('falls back to the raw key when the native set() fails', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $custodian->setSucceeds = false;
    $raw = random_bytes(32);

    // Degraded pass-through: the raw key becomes the handle, and read() sees
    // it does not start with the slot prefix and returns it unchanged.
    $handle = $custodian->store($raw);
    expect($handle)->toBe($raw)
        ->and($custodian->read($handle))->toBe($raw);
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
