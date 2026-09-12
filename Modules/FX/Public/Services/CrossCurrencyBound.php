<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Modules\Ledger\Public\ValueObjects\Money;

// One typed figure is one amount of money, so a surface that tests rows in
// several currencies restates the bound in each of them rather than applying
// the raw integer to all at once -- which made "at least 20" mean EUR 20, USD
// 20 and 20 yen simultaneously.
/**
 * @link ../../../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md#the-other-half-comparing-two-denominations-as-bare-integers
 */
final readonly class CrossCurrencyBound
{
    public function __construct(private CrossCurrencyTotal $fx) {}

    // Null, never a 1:1, where no rate reaches the currency: a bound that had
    // a value and lost it in conversion leaves the filter unanswerable, and
    // reporting on the surviving side alone would silently widen the window
    // the reader asked for.
    /**
     * @return ?array{min: ?int, max: ?int}
     */
    public function restate(?int $minMinor, ?int $maxMinor, string $from, string $to): ?array
    {
        if ($from === $to || ($minMinor === null && $maxMinor === null)) {
            return ['min' => $minMinor, 'max' => $maxMinor];
        }

        $rates = $this->fx->ratesTo([$from], $to);

        $min = $minMinor === null ? null : $this->inCurrency($minMinor, $from, $to, $rates);
        $max = $maxMinor === null ? null : $this->inCurrency($maxMinor, $from, $to, $rates);

        $lost = ($minMinor !== null && $min === null) || ($maxMinor !== null && $max === null);

        return $lost ? null : ['min' => $min, 'max' => $max];
    }

    /**
     * @param  array<string, string>  $rates
     */
    private function inCurrency(int $minor, string $from, string $to, array $rates): ?int
    {
        $money = Money::tryOfMinor($minor, $from);

        return $money === null ? null : $this->fx->convert($money, $to, $rates)?->toMinor();
    }
}
