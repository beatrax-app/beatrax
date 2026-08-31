<?php

declare(strict_types=1);

use Modules\Ledger\Public\Support\OutwardSpend;

// The one definition of directional spend, asked the four questions that used
// to be answered separately: what is ranked, what the whole is, what a share of
// it comes to, and what was left out.
// @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-one-directional-figure-ranked-on-a-signed-sum

it('ranks only the keys running outward, largest first', function (): void {
    $spend = OutwardSpend::from([7 => 5000, 8 => -35000, 9 => 8000]);

    expect($spend->rankedMinor)->toBe([9 => 8000, 7 => 5000]);
    expect($spend->totalMinor)->toBe(13000);
    expect($spend->inwardMinor)->toBe(-35000);
    expect($spend->inwardCount)->toBe(1);
});

it('cuts every share from the ranked total alone, so the shares add back up to it', function (): void {
    $spend = OutwardSpend::from([1 => 8000, 2 => 2000, 3 => -12500]);

    $shares = array_map(static fn (int $minor): float => $spend->shareOf($minor), $spend->rankedMinor);

    expect(abs(array_sum($shares) - 1.0))->toBeLessThan(0.0001);
    foreach ($shares as $share) {
        expect($share)->toBeGreaterThan(0.0);
        expect($share)->toBeLessThanOrEqual(1.0);
    }
});

it('leaves nothing ranked when every key ran the other way, and names what came back', function (): void {
    $spend = OutwardSpend::from([1 => -3000, 2 => -4500]);

    expect($spend->rankedMinor)->toBe([]);
    expect($spend->totalMinor)->toBe(0);
    expect($spend->inwardMinor)->toBe(-7500);
    expect($spend->inwardCount)->toBe(2);
});

it('counts a category refunded to exactly what it cost as neither', function (): void {
    $spend = OutwardSpend::from([1 => 0, 2 => 6000]);

    expect($spend->rankedMinor)->toBe([2 => 6000]);
    expect($spend->inwardMinor)->toBe(0);
    expect($spend->inwardCount)->toBe(0);
});

it('narrows before it limits, so a refunded category never takes a place in the top N', function (): void {
    $spend = OutwardSpend::from([1 => 100, 2 => -90000, 3 => 300, 4 => 200], limit: 2);

    expect($spend->rankedMinor)->toBe([3 => 300, 4 => 200]);
    expect($spend->totalMinor)->toBe(500);
});

it('refuses a share whose part or whole is not positive, rather than returning one', function (): void {
    expect(OutwardSpend::share(-1500, 8000))->toBe(0.0);
    expect(OutwardSpend::share(8000, 0))->toBe(0.0);
    expect(OutwardSpend::share(8000, -500))->toBe(0.0);
    expect(OutwardSpend::share(2000, 8000))->toBe(0.25);
});
