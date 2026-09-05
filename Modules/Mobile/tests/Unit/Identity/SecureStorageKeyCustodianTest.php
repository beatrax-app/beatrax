<?php

declare(strict_types=1);

use Modules\Auth\Public\Enums\KeyCustody;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Exceptions\SecureStorageException;
use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;
use Modules\Mobile\Tests\Support\FakeSecureStorageCustodian;

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

// A store that answers on a phone is a real one -- the iOS entry is
// kSecAttrAccessibleWhenUnlockedThisDeviceOnly and the Android one an
// EncryptedSharedPreferences value under a Keystore master key -- so the report
// has no third case to make, unlike a Linux desktop with no keyring.

it('reports session custody where the mobile runtime is unavailable', function (): void {
    $custody = (new SecureStorageKeyCustodian(secureStorageCurrentUser(1)))->custody();

    expect($custody)->toBe(KeyCustody::Session)
        ->and($custody->protectsAtRest())->toBeFalse();
});

it('reports operating-system custody on device', function (): void {
    $custody = (new FakeSecureStorageCustodian(secureStorageCurrentUser(7)))->custody();

    expect($custody)->toBe(KeyCustody::OperatingSystem)
        ->and($custody->protectsAtRest())->toBeTrue();
});

// The handle in the session is the slot name, and the report says the key
// behind it is the operating system's. Both halves matter: a report of
// operating-system custody over a session that still held the raw key would be
// the same false claim in the other direction.
it('keeps the raw key out of the handle it reports operating-system custody for', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $raw = random_bytes(32);

    $handle = $custodian->store($raw);

    expect($custodian->custody())->toBe(KeyCustody::OperatingSystem)
        ->and($handle)->toBe('beatrax.session.data_key.7')
        ->and($handle)->not->toBe($raw)
        ->and($custodian->slots[$handle])->not->toBe($raw);
});

// The upgrade path. A phone whose session predates custody holds the raw key
// under no slot prefix, and the prefix is what says whether a handle names a
// Keychain entry. Unprefixed means the handle IS the key, so the reader is not
// sent back to the PIN screen by the upgrade itself; the next lock/unlock moves
// it into the store.
it('carries a pre-custody session through unchanged rather than asking for the PIN again', function (): void {
    $custodian = new FakeSecureStorageCustodian(secureStorageCurrentUser(7));
    $raw = random_bytes(32);

    expect($custodian->read($raw))->toBe($raw)
        ->and($custodian->slots)->toBe([]);
});
