<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;
use Modules\Mobile\Tests\Support\FakeBiometricKeyVault;
use Psr\Log\NullLogger;

function fakeVault(): FakeBiometricKeyVault
{
    return new FakeBiometricKeyVault(new BiometricKeyBlobCodec(new AppLockKeyWrap), new NullLogger);
}

it('round-trips the data key through enroll() then recover()', function (): void {
    $vault = fakeVault();
    $dataKey = random_bytes(32);

    expect($vault->enroll(7, $dataKey))->toBeTrue();

    $result = $vault->recover(7);

    expect($result->isRecovered())->toBeTrue()
        ->and($result->dataKey)->toBe($dataKey);
});

it('stores a wrapped blob per user, never the raw key', function (): void {
    $vault = fakeVault();
    $dataKey = str_repeat("\x2a", 32);

    $vault->enroll(7, $dataKey);

    expect($vault->store)->toHaveKey('beatrax.coldstart.datakey.7')
        ->and(base64_decode($vault->store['beatrax.coldstart.datakey.7'], true))->not->toBe($dataKey);
});

it('returns missing when nothing is enrolled', function (): void {
    expect(fakeVault()->recover(7)->status)->toBe(BiometricRecoverResult::MISSING);
});

it('maps a native cancel to CANCELED (not missing — the caller must not treat it as no-key)', function (): void {
    $vault = fakeVault();
    $vault->enroll(7, random_bytes(32));
    $vault->forcedGet = ['value' => '', 'canceled' => true];

    expect($vault->recover(7)->status)->toBe(BiometricRecoverResult::CANCELED);
});

it('maps a native failed flag to FAILED (enrolled but auth failed — NOT missing)', function (): void {
    $vault = fakeVault();
    $vault->enroll(7, random_bytes(32));
    $vault->forcedGet = ['value' => '', 'failed' => true];

    expect($vault->recover(7)->status)->toBe(BiometricRecoverResult::FAILED);
});

it('maps an empty native result to FAILED (bridge error — NOT missing)', function (): void {
    $vault = fakeVault();
    $vault->forcedGet = [];

    expect($vault->recover(7)->status)->toBe(BiometricRecoverResult::FAILED);
});

it('maps a native async marker (Android) to PENDING_ASYNC', function (): void {
    $vault = fakeVault();
    $vault->forcedGet = ['async' => true, 'event' => 'BiometricVault.Recovered'];

    expect($vault->recover(7)->status)->toBe(BiometricRecoverResult::PENDING_ASYNC);
});

it('returns UNAVAILABLE and enroll()=false off-device', function (): void {
    $vault = fakeVault();
    $vault->available = false;

    expect($vault->enroll(7, random_bytes(32)))->toBeFalse()
        ->and($vault->recover(7)->status)->toBe(BiometricRecoverResult::UNAVAILABLE);
});

it('returns missing after clear()', function (): void {
    $vault = fakeVault();
    $vault->enroll(7, random_bytes(32));
    expect($vault->recover(7)->isRecovered())->toBeTrue();

    $vault->clear(7);

    expect($vault->recover(7)->status)->toBe(BiometricRecoverResult::MISSING);
});

it('returns missing when the stored blob is corrupt (fails closed)', function (): void {
    $vault = fakeVault();
    $vault->store['beatrax.coldstart.datakey.7'] = base64_encode('too-short-not-a-real-blob');

    expect($vault->recover(7)->status)->toBe(BiometricRecoverResult::MISSING);
});

it('completePendingRecover round-trips a stashed blob to RECOVERED', function (): void {
    $vault = fakeVault();
    $dataKey = random_bytes(32);
    // What the async native callback would have decrypted: base64 of the real
    // biometric blob, produced by the same codec the vault uses.
    $vault->pollValue = base64_encode((new BiometricKeyBlobCodec(new AppLockKeyWrap))->wrap($dataKey));

    $result = $vault->completePendingRecover();

    expect($result->isRecovered())->toBeTrue()
        ->and($result->dataKey)->toBe($dataKey);
});

it('completePendingRecover returns MISSING when nothing is stashed', function (): void {
    $vault = fakeVault();
    $vault->pollValue = null;

    expect($vault->completePendingRecover()->status)->toBe(BiometricRecoverResult::MISSING);
});

it('completePendingRecover returns MISSING on a corrupt stashed blob (fails closed)', function (): void {
    $vault = fakeVault();
    $vault->pollValue = 'not+valid+base64+!!!';

    expect($vault->completePendingRecover()->status)->toBe(BiometricRecoverResult::MISSING);
});

it('completePendingRecover returns UNAVAILABLE off-device', function (): void {
    $vault = fakeVault();
    $vault->available = false;
    $vault->pollValue = base64_encode((new BiometricKeyBlobCodec(new AppLockKeyWrap))->wrap(random_bytes(32)));

    expect($vault->completePendingRecover()->status)->toBe(BiometricRecoverResult::UNAVAILABLE);
});

// The slot name carries the OWNING user id, which the caller names. Read from
// the session instead, one store() overwrote the other account's key, and a
// console or job caller — which has no authenticated user at all — threw.

it("writes each user's key into that user's own enclave slot", function (): void {
    $vault = fakeVault();
    $first = str_repeat("\x01", 32);
    $second = str_repeat("\x02", 32);

    $vault->enroll(1, $first);
    $vault->enroll(2, $second);

    expect(array_keys($vault->store))->toBe(['beatrax.coldstart.datakey.1', 'beatrax.coldstart.datakey.2'])
        ->and($vault->recover(1)->dataKey)->toBe($first)
        ->and($vault->recover(2)->dataKey)->toBe($second);
});

it('clears only the slot it was asked for', function (): void {
    $vault = fakeVault();
    $vault->enroll(1, random_bytes(32));
    $vault->enroll(2, random_bytes(32));

    $vault->clear(2);

    expect($vault->recover(1)->isRecovered())->toBeTrue()
        ->and($vault->recover(2)->status)->toBe(BiometricRecoverResult::MISSING);
});
