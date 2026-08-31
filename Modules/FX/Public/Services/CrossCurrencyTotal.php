<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Modules\FX\Public\Dto\ConvertedTotal;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\RateTable;

// Adding minor units across currencies is the arithmetic AccountBalance exists
// to prevent. Every roll-up that spans them converts each bucket at its own
// rate, leaves out a currency the rate table cannot reach rather than counting
// it at one to one, and names what it left out.
/**
 * @link ../../../../.docs/features/fx/architecture.md
 */
final readonly class CrossCurrencyTotal
{
    public function __construct(private ExchangeRateService $fx) {}

    /**
     * @param  array<string, int>  $minorByCurrency
     */
    public function of(array $minorByCurrency, string $targetCurrency): ConvertedTotal
    {
        return $this->withRates(
            $minorByCurrency,
            $targetCurrency,
            $this->ratesTo(array_keys($minorByCurrency), $targetCurrency),
        );
    }

    // One lookup per currency, never one per bucket: convertToBase() reads the
    // whole exchange_rates table on every call. The rate a zero amount converts
    // at is the rate any amount converts at.
    /**
     * @param  list<string>  $currencies
     * @return array<string, string> currency code => decimal rate into $targetCurrency
     */
    public function ratesTo(array $currencies, string $targetCurrency): array
    {
        $rates = [];
        foreach (array_unique($currencies) as $currency) {
            if ($currency === $targetCurrency || $currency === '' || isset($rates[$currency])) {
                continue;
            }

            $probe = Money::tryOfMinor(0, $currency);
            $rate = $probe === null ? null : $this->fx->convertToBase($probe, $targetCurrency)->rate;

            if ($rate !== null) {
                $rates[$currency] = $rate;
            }
        }

        return $rates;
    }

    /**
     * @param  array<string, int>  $minorByCurrency
     * @param  array<string, string>  $rates  as returned by ratesTo()
     */
    public function withRates(array $minorByCurrency, string $targetCurrency, array $rates): ConvertedTotal
    {
        $minor = 0;
        $unconverted = [];

        foreach ($minorByCurrency as $currency => $bucketMinor) {
            if ($currency === $targetCurrency) {
                $minor += $bucketMinor;

                continue;
            }

            $money = Money::tryOfMinor($bucketMinor, $currency);
            $converted = $money === null ? null : $this->convert($money, $targetCurrency, $rates);

            if ($converted === null) {
                $unconverted[$currency] = true;

                continue;
            }

            $minor += $converted->toMinor();
        }

        $codes = array_keys($unconverted);
        sort($codes);

        return new ConvertedTotal(minor: $minor, currency: $targetCurrency, unconverted: $codes);
    }

    // Converting each part on its own drifts by up to half a minor unit per
    // part, so the parts stop adding up to the whole they came from: one report
    // totalled EUR 8942.01 by category and EUR 8942.04 by account, and the
    // dashboard read a category a cent under the figure /reports gave it.
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $partsMinor  all denominated in $currency
     * @param  array<string, string>  $rates  as returned by ratesTo()
     * @return ?array<TKey, int> converted, summing exactly to the converted subtotal; null when the pair has no rate
     */
    public function distribute(array $partsMinor, string $currency, string $targetCurrency, array $rates): ?array
    {
        if ($partsMinor === []) {
            return [];
        }

        $subtotal = Money::tryOfMinor(array_sum($partsMinor), $currency);
        $convertedSubtotal = $subtotal === null ? null : $this->convert($subtotal, $targetCurrency, $rates);

        if ($convertedSubtotal === null) {
            return null;
        }

        $converted = [];
        $sumOfParts = 0;
        foreach ($partsMinor as $key => $partMinor) {
            $money = Money::tryOfMinor($partMinor, $currency);
            $convertedPart = $money === null ? null : $this->convert($money, $targetCurrency, $rates);

            if ($convertedPart === null) {
                return null;
            }

            $converted[$key] = $convertedPart->toMinor();
            $sumOfParts += $converted[$key];
        }

        return self::spreadRemainder($converted, $partsMinor, $convertedSubtotal->toMinor() - $sumOfParts);
    }

    // distribute() converts a whole it derives from the parts; this one splits
    // a whole the record already carries, in the parts' proportion. Rounding
    // each part alone drifts either side of that line: three legs of a $30.00
    // charge printed $29.99, and a quarter of a 200,000-case fuzz drifted too.
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weightsMinor  all denominated in one currency, and summing to what $wholeMinor is the whole of
     * @return ?array<TKey, int> summing exactly to $wholeMinor; null when the weights sum to zero and name no proportion
     */
    public static function apportion(int $wholeMinor, array $weightsMinor): ?array
    {
        if ($weightsMinor === []) {
            return [];
        }

        $weightTotal = array_sum($weightsMinor);

        if ($weightTotal === 0) {
            return null;
        }

        $shares = [];
        $sumOfShares = 0;
        foreach ($weightsMinor as $key => $weightMinor) {
            $shares[$key] = self::proRataShare($wholeMinor, $weightMinor, $weightTotal);
            $sumOfShares += $shares[$key];
        }

        return self::spreadRemainder($shares, $weightsMinor, $wholeMinor - $sumOfShares);
    }

    // Rounded half away from zero, on magnitudes: the quotient is negative
    // when an odd number of the three is, and mixed-sign parts do reach here.
    private static function proRataShare(int $wholeMinor, int $weightMinor, int $weightTotal): int
    {
        $magnitude = intdiv(
            abs($wholeMinor) * abs($weightMinor) * 2 + abs($weightTotal),
            abs($weightTotal) * 2,
        );

        $negatives = (int) ($wholeMinor < 0) + (int) ($weightMinor < 0) + (int) ($weightTotal < 0);

        return $negatives % 2 === 1 ? -$magnitude : $magnitude;
    }

    // Largest magnitude first, ties broken by position, so the same figures
    // always land the same cents on the same parts.
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $converted
     * @param  array<TKey, int>  $partsMinor
     * @return array<TKey, int>
     */
    private static function spreadRemainder(array $converted, array $partsMinor, int $remainder): array
    {
        if ($remainder === 0) {
            return $converted;
        }

        $positions = array_flip(array_keys($partsMinor));
        $order = array_keys($converted);
        usort($order, static function (int|string $a, int|string $b) use ($partsMinor, $positions): int {
            $byMagnitude = abs($partsMinor[$b]) <=> abs($partsMinor[$a]);

            return $byMagnitude === 0 ? $positions[$a] <=> $positions[$b] : $byMagnitude;
        });

        $step = $remainder > 0 ? 1 : -1;
        for ($i = 0; $i < abs($remainder); $i++) {
            $converted[$order[$i % count($order)]] += $step;
        }

        return $converted;
    }

    // Null rather than the original amount for a pair with no rate, so a caller
    // that renders the result under the target currency's sign cannot print an
    // unconverted figure there.
    /**
     * @param  array<string, string>  $rates  as returned by ratesTo()
     */
    public function convert(Money $money, string $targetCurrency, array $rates): ?Money
    {
        if ($money->currency() === $targetCurrency) {
            return $money;
        }

        $rate = $rates[$money->currency()] ?? null;

        if ($rate === null) {
            return null;
        }

        return RateTable::direct()
            ->withRate($money->currency(), $targetCurrency, $rate)
            ->convert($money, $targetCurrency);
    }
}
