<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\NullColdStartVault;
use Modules\Auth\Public\Contracts\ColdStartVault;

/*
 * The default on web, CI, and any desktop build without a biometric gate.
 *
 * It exists so the lock screen can always inject the contract instead of
 * branching on whether a platform implementation happens to be registered —
 * which means every answer has to be the safe one. Enrolling has to fail
 * rather than appear to succeed, or the lock screen offers an unlock that
 * cannot work and the user is left staring at a dead button with no PIN
 * prompt in reach.
 */

it('satisfies the contract the lock screen injects', function (): void {
    expect(new NullColdStartVault)->toBeInstanceOf(ColdStartVault::class);
});

it('never claims a biometric gate it does not have', function (): void {
    $vault = new NullColdStartVault;

    expect($vault->isAvailable())->toBeFalse()
        ->and($vault->isEnrolled(1))->toBeFalse();
});

// Refusing rather than pretending: a true here would light up an unlock path
// with nothing behind it.
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
