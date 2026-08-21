<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\NullColdStartVault;
use Modules\Auth\Public\Contracts\ColdStartVault;

// It exists so the lock screen injects the contract rather than branching on
// its absence, which makes every answer here obliged to be the safe one.

it('satisfies the contract the lock screen injects', function (): void {
    expect(new NullColdStartVault)->toBeInstanceOf(ColdStartVault::class);
});

it('never claims a biometric gate it does not have', function (): void {
    $vault = new NullColdStartVault;

    expect($vault->isAvailable())->toBeFalse()
        ->and($vault->isEnrolled(1))->toBeFalse();
});

// A true here would light up an unlock path with nothing behind it.
it('refuses to enroll and recovers nothing', function (): void {
    $vault = new NullColdStartVault;

    expect($vault->enroll(1, random_bytes(32)))->toBeFalse()
        ->and($vault->isEnrolled(1))->toBeFalse()
        ->and($vault->recover(1, 'Unlock beatrax'))->toBeNull();
});

it('forgets without complaint, since there is never anything stored', function (): void {
    $vault = new NullColdStartVault;

    expect(fn () => $vault->forget(1))->not->toThrow(Throwable::class);
});
