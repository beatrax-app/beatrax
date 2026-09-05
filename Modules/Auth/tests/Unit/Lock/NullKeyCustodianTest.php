<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\NullKeyCustodian;
use Modules\Auth\Public\Enums\KeyCustody;

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

// The handle IS the key here, so the session is the whole custody. Saying so
// is what stops a caller persisting key material on a self-hosted install
// believing an operating-system store is behind it.
it('reports session custody, which does not protect at rest', function (): void {
    $custody = (new NullKeyCustodian)->custody();

    expect($custody)->toBe(KeyCustody::Session)
        ->and($custody->protectsAtRest())->toBeFalse();
});
