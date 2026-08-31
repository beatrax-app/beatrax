<?php

declare(strict_types=1);

use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\Rate;

beforeEach(function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
});

// Every other demo account is on the 1/100 scale, where a hardcoded division
// by a hundred and the currency's own divisor agree on every figure. Without
// a zero-decimal account no surface in the app can be shown to have got the
// scale right rather than got lucky.
it('carries an account whose currency has no minor unit at all', function (): void {
    $zeroDecimal = Account::query()
        ->get(['id', 'default_currency'])
        ->filter(static fn (Account $a): bool => Money::tryOfMinor(0, (string) $a->default_currency)?->minorUnitsPerMajor() === 1);

    expect($zeroDecimal)->not->toBeEmpty();

    $rows = Transaction::query()
        ->where('source_format', 'demo')
        ->whereIn('account_id', $zeroDecimal->pluck('id')->all())
        ->get(['amount_minor', 'currency']);

    expect($rows->count())->toBeGreaterThanOrEqual(10);

    // A figure a stray hundredth would render as three digits instead of six,
    // which no reader could mistake for a rounding difference.
    expect($rows->contains(static fn (Transaction $t): bool => abs((int) $t->amount_minor) >= 100000))->toBeTrue();
});

// A pair whose legs sit on different minor-unit scales: the euro leg holds
// hundredths and the yen leg holds whole units, so the rate between them is
// not the ratio of the two stored integers.
it('carries a cross-currency pair whose two legs are on different scales', function (): void {
    $crossScale = Transaction::query()
        ->where('source_format', 'demo')
        ->whereNotNull('fx_rate_used')
        ->whereColumn('currency', '!=', 'settled_currency')
        ->get(['amount_minor', 'currency', 'settled_amount_minor', 'settled_currency', 'fx_rate_used'])
        ->filter(static function (Transaction $t): bool {
            $native = Money::tryOfMinor(0, (string) $t->currency);
            $settled = Money::tryOfMinor(0, (string) $t->settled_currency);

            return $native !== null
                && $settled !== null
                && $native->minorUnitsPerMajor() !== $settled->minorUnitsPerMajor();
        });

    expect($crossScale)->not->toBeEmpty();

    foreach ($crossScale as $tx) {
        $derived = Rate::between(
            Money::ofMinor((int) $tx->settled_amount_minor, (string) $tx->settled_currency),
            Money::ofMinor((int) $tx->amount_minor, (string) $tx->currency),
        );

        // Both at the column's own scale: SQLite's numeric affinity hands
        // back a stored 0.00580000 as 0.0058.
        expect((string) $derived?->toScale(Rate::SCALE))
            ->toBe((string) Rate::of((string) $tx->fx_rate_used)?->toScale(Rate::SCALE));
    }
});
