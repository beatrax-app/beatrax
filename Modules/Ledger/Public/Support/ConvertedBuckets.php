<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Modules\FX\Public\Dto\RateSet;
use Modules\FX\Public\Services\CrossCurrencyTotal;

// One roll-up's buckets converted in one go: grouped by the currency they were
// stored in and spread back over the keys, never converted key by key. Each key
// converted alone drifts up to half a minor unit, so one rent envelope read JPY
// 198,875 on the budgets grid and JPY 198,874 on the dashboard's category card.
/**
 * @link ../../../../.docs/features/ledger/architecture.md#conversion-is-grouped-by-currency-not-by-category
 */
final readonly class ConvertedBuckets
{
    /**
     * @param  array<int, int>  $minorByKey  every key the buckets named, in $rates->targetCurrency
     * @param  list<string>  $unconverted  codes no rate reached, left out of every key above
     */
    private function __construct(
        public array $minorByKey,
        public array $unconverted,
        public RateSet $rates,
    ) {}

    /**
     * @param  array<int, array<string, int>>  $bucketsByKey  key => currency => minor
     */
    public static function of(
        CrossCurrencyTotal $fx,
        array $bucketsByKey,
        string $targetCurrency,
        RateSet $rates,
    ): self {
        $minorByKey = [];
        $byCurrency = [];

        foreach ($bucketsByKey as $key => $byCode) {
            $minorByKey[$key] = 0;
            foreach ($byCode as $code => $minor) {
                $byCurrency[$code][$key] = $minor;
            }
        }

        $unconverted = [];

        foreach ($byCurrency as $code => $parts) {
            // All or nothing per currency, which is what distribute() answers:
            // a partial spread would not sum to the subtotal it came from.
            $converted = $fx->distribute($parts, $code, $targetCurrency, $rates);

            if ($converted === null) {
                $unconverted[] = $code;

                continue;
            }

            foreach ($converted as $key => $minor) {
                $minorByKey[$key] += $minor;
            }
        }

        sort($unconverted);

        return new self(
            $minorByKey,
            $unconverted,
            $rates->only(array_values(array_diff(array_keys($byCurrency), $unconverted))),
        );
    }
}
