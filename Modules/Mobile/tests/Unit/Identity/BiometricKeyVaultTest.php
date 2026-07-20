<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;

/*
 * Unit tests for BiometricKeyVault — the cold-start enclave vault.
 *
 * The native BiometricVault facade is unreachable in the repo toolchain, so a
 * subclass overrides the runtimeAvailable/vaultSet/vaultGet/vaultDelete seams
 * with an in-memory enclave. The blob crypto (BiometricKeyBlobCodec) is REAL,
 * so the enroll→recover round-trip exercises the true wrap/unwrap path; only
 * the OS biometric gate is faked (its release decision is the on-device UAT).
 */

class FakeBiometricKeyVault extends BiometricKeyVault
{
    /** @var array<string, string> */
    public array $store = [];

    public bool $available = true;

    /** @var array<string, mixed>|null forces a specific native get() outcome */
    public ?array $forcedGet = null;

    /** Value the async pollRecovered() seam returns (base64 blob, or null). */
    public ?string $pollValue = null;

    protected function runtimeAvailable(): bool
    {
        return $this->available;
    }

    protected function pollRecovered(): ?string
    {
        return $this->pollValue;
    }

    protected function vaultSet(string $key, string $value): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    protected function vaultGet(string $key, string $reason): array
    {
        if ($this->forcedGet !== null) {
            return $this->forcedGet;
        }

        return isset($this->store[$key])
            ? ['value' => $this->store[$key], 'authenticated' => true]
            : ['value' => '', 'missing' => true];
    }

    protected function vaultDelete(string $key): void
    {
        unset($this->store[$key]);
    }
}

function fakeVault(int $userId = 7): FakeBiometricKeyVault
{
    $cu = Mockery::mock(CurrentUser::class);
    $cu->shouldReceive('id')->andReturn($userId);

    return new FakeBiometricKeyVault(new BiometricKeyBlobCodec(new AppLockKeyWrap), $cu);
}

it('round-trips the data key through enroll() then recover()', function (): void {
    $vault = fakeVault();
    $dataKey = random_bytes(32);

    expect($vault->enroll($dataKey))->toBeTrue();

    $result = $vault->recover();

    expect($result->isRecovered())->toBeTrue()
        ->and($result->dataKey)->toBe($dataKey);
});

it('stores a wrapped blob per user, never the raw key', function (): void {
    $vault = fakeVault(7);
    $dataKey = str_repeat("\x2a", 32);

    $vault->enroll($dataKey);

    expect($vault->store)->toHaveKey('beatrax.coldstart.datakey.7')
        ->and(base64_decode($vault->store['beatrax.coldstart.datakey.7'], true))->not->toBe($dataKey);
});

it('returns missing when nothing is enrolled', function (): void {
    expect(fakeVault()->recover()->status)->toBe(BiometricRecoverResult::MISSING);
});

it('maps a native cancel to CANCELED (not missing — the caller must not treat it as no-key)', function (): void {
    $vault = fakeVault();
    $vault->enroll(random_bytes(32));
    $vault->forcedGet = ['value' => '', 'canceled' => true];

    expect($vault->recover()->status)->toBe(BiometricRecoverResult::CANCELED);
});

it('maps a native failed flag to FAILED (enrolled but auth failed — NOT missing)', function (): void {
    $vault = fakeVault();
    $vault->enroll(random_bytes(32));
    $vault->forcedGet = ['value' => '', 'failed' => true];

    expect($vault->recover()->status)->toBe(BiometricRecoverResult::FAILED);
});

it('maps an empty native result to FAILED (bridge error — NOT missing)', function (): void {
    $vault = fakeVault();
    $vault->forcedGet = [];

    expect($vault->recover()->status)->toBe(BiometricRecoverResult::FAILED);
});

it('maps a native async marker (Android) to PENDING_ASYNC', function (): void {
    $vault = fakeVault();
    $vault->forcedGet = ['async' => true, 'event' => 'BiometricVault.Recovered'];

    expect($vault->recover()->status)->toBe(BiometricRecoverResult::PENDING_ASYNC);
});

it('returns UNAVAILABLE and enroll()=false off-device', function (): void {
    $vault = fakeVault();
    $vault->available = false;

    expect($vault->enroll(random_bytes(32)))->toBeFalse()
        ->and($vault->recover()->status)->toBe(BiometricRecoverResult::UNAVAILABLE);
});

it('returns missing after clear()', function (): void {
    $vault = fakeVault();
    $vault->enroll(random_bytes(32));
    expect($vault->recover()->isRecovered())->toBeTrue();

    $vault->clear();

    expect($vault->recover()->status)->toBe(BiometricRecoverResult::MISSING);
});

it('returns missing when the stored blob is corrupt (fails closed)', function (): void {
    $vault = fakeVault();
    $vault->store['beatrax.coldstart.datakey.7'] = base64_encode('too-short-not-a-real-blob');

    expect($vault->recover()->status)->toBe(BiometricRecoverResult::MISSING);
});

// --- completePendingRecover() (Android async) --------------------------------

it('completePendingRecover round-trips a stashed blob to RECOVERED', function (): void {
    $vault = fakeVault();
    $dataKey = random_bytes(32);
    // Stash what the async native callback would have decrypted: base64 of the
    // real biometric blob, produced by the same codec the vault uses.
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
