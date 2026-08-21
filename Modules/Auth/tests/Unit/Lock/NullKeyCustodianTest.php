<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\NullKeyCustodian;

// The pass-through default keeps the custody seam behaviourally invisible
// until a native bundle overrides the binding.

it('returns the raw key unchanged from store()', function (): void {
    $custodian = new NullKeyCustodian;
    $raw = str_repeat("\x2a", 32);

    expect($custodian->store($raw))->toBe($raw);
});

it('returns the handle unchanged from read()', function (): void {
    $custodian = new NullKeyCustodian;
    $raw = random_bytes(32);

    expect($custodian->read($custodian->store($raw)))->toBe($raw);
});

it('forget() is a no-op and does not throw', function (): void {
    $custodian = new NullKeyCustodian;

    expect(fn () => $custodian->forget('anything'))->not->toThrow(Throwable::class);
});
