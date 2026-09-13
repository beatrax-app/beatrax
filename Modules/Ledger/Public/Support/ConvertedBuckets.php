<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Modules\FX\Public\Dto\ConversionDisclosure;
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
     * @param  array<int, int>  $minorByKey  every key the buckets named, in $conversion's currency
     * @param  list<string>  $unconverted  codes no rate reached, left out of every key above
     * @param  array<int, array<string, int>>  $bucketsByKey  kept so a key can answer for its own codes
     */
    private function __construct(
        public array $minorByKey,
        public array $unconverted,
        public ConversionDisclosure $conversion,
        private array $bucketsByKey,
        private RateSet $rates,
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

        $priced = $rates->only(array_values(array_diff(array_keys($byCurrency), $unconverted)));

        return new self(
            $minorByKey,
            $unconverted,
            ConversionDisclosure::of($priced, $unconverted),
            $bucketsByKey,
            $priced,
        );
    }

    // What one key's own figure has to say about itself, which is narrower than
    // the roll-up's on both halves: a batched read shared by two dozen rows must
    // not make each of them disclose the other twenty-three's rates, and a code
    // no rate reached is only this row's to name where this row holds some of it.
    public function conversionFor(int $key): ConversionDisclosure
    {
        $codes = array_keys($this->bucketsByKey[$key] ?? []);

        return ConversionDisclosure::of($this->rates->only($codes), $this->unconvertedFor($key));
    }

    // A bucket that nets to nought is money the total is not missing, whichever
    // currency it was in: an XPF 1,000 spend against an ARS 10.00 return cancels
    // to nothing, and a badge over nothing is a badge over nothing.
    /**
     * @return list<string>
     */
    public function unconvertedFor(int $key): array
    {
        $held = $this->bucketsByKey[$key] ?? [];

        return array_values(array_filter(
            $this->unconverted,
            static fn (string $code): bool => ($held[$code] ?? 0) !== 0,
        ));
    }
}
