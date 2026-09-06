<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayRateLimiter;

// Both limiters prune before they insert, and both insert whether or not the
// prune freed anything: pruneExpired() drops only windows whose full duration
// has elapsed, so a burst of distinct source keys inside ONE window frees
// nothing and the map grows for every key. The cap each class documents was a
// description of when the sweep runs, never a bound on the map — which makes a
// network-facing limiter the exhaustion vector it was written to prevent.

function floodingClock(): Clock
{
    return new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-09-05T12:00:00Z');
        }
    };
}

// Read through whatever holds the map rather than through a fixed shape, so the
// same assertion measures the limiter before and after the map is extracted
// into a seam the two of them share.
function trackedSourceCount(object $limiter): int
{
    $held = (new ReflectionProperty($limiter, 'windows'))->getValue($limiter);

    if (is_array($held)) {
        return count($held);
    }

    if (is_object($held)) {
        $inner = (new ReflectionProperty($held, 'windows'))->getValue($held);

        return is_array($inner) ? count($inner) : -1;
    }

    return -1;
}

function trackedSourceCap(object $limiter): int
{
    $cap = (new ReflectionClass($limiter))->getConstant('MAX_TRACKED_SOURCES');

    return is_int($cap) ? $cap : -1;
}

/** @return array{peak: int, cap: int} */
function floodDistinctSources(object $limiter, int $extra): array
{
    $cap = trackedSourceCap($limiter);
    $peak = 0;

    for ($i = 0; $i < $cap + $extra; $i++) {
        $limiter->allow('198.51.100.'.$i);
        $peak = max($peak, trackedSourceCount($limiter));
    }

    return ['peak' => $peak, 'cap' => $cap];
}

it('holds the map at the cap it documents while distinct sources flood one window', function (object $limiter): void {
    expect(trackedSourceCap($limiter))->toBeGreaterThan(0, 'the cap has to be readable for this to measure anything');

    ['peak' => $peak, 'cap' => $cap] = floodDistinctSources($limiter, 200);

    expect($peak)->toBeLessThanOrEqual($cap, implode("\n  ", [
        'The window map grew past the number the class names as its cap. Every key is',
        'one entry a stranger can add for the cost of a packet, and nothing inside the',
        'window can remove one, so the ceiling is however many distinct sources reach',
        'the socket in a minute.',
        'cap: '.$cap.', peak: '.$peak,
    ]));
})->with([
    'pairing offers' => [fn (): object => new PairingOfferRateLimiter(floodingClock())],
    'relay endpoints' => [fn (): object => new RelayRateLimiter(floodingClock())],
]);

// Eviction is a real weakening and it is bounded: the source that loses its
// window gets a fresh one, so a flood buys an attacker a reset for the entries
// it pushed out. Pinned rather than left implied, because the alternative to
// this weakening is an unbounded map.
it('gives an evicted source a fresh window, and still refuses one it is still tracking', function (): void {
    $limiter = new PairingOfferRateLimiter(floodingClock());

    for ($attempt = 0; $attempt < PairingOfferRateLimiter::MAX_PER_WINDOW; $attempt++) {
        expect($limiter->allow('203.0.113.1'))->toBeTrue();
    }

    expect($limiter->allow('203.0.113.1'))->toBeFalse('the limiter has to work before eviction can weaken it');

    floodDistinctSources($limiter, 0);

    expect($limiter->allow('203.0.113.1'))
        ->toBeTrue('the first source in is the first evicted, and an evicted source is one the limiter no longer knows');

    // A source still in the map is unaffected: it has spent one of its budget
    // on the way in, so the rest of the budget and no more is what it has left.
    $stillTracked = '198.51.100.'.(trackedSourceCap($limiter) - 1);

    for ($attempt = 1; $attempt < PairingOfferRateLimiter::MAX_PER_WINDOW; $attempt++) {
        expect($limiter->allow($stillTracked))->toBeTrue();
    }

    expect($limiter->allow($stillTracked))->toBeFalse('eviction must not hand a live window a second budget');
});
