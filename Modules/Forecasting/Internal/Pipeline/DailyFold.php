<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\ValueObjects\Money;

final readonly class DailyFold
{
    public function __construct(private CrossCurrencyTotal $fx) {}

    /**
     * @param  list<ForecastContribution>  $contributions
     * @param  array<string, string>  $rates  as returned by CrossCurrencyTotal::ratesTo()
     */
    public function fold(
        int $openingBalanceMinor,
        array $contributions,
        CarbonImmutable $asOf,
        int $horizonDays,
        string $defaultCurrency,
        array $rates,
    ): DailyFoldResult {
        // spread_sq accumulates half-widths squared: same-day uncertainties
        // combine in quadrature, not by addition.
        /** @var array<string, array{point_minor: int, spread_sq: float}> $buckets */
        $buckets = [];

        /** @var array<string, true> $unconverted */
        $unconverted = [];

        // Day 0 is the anchor: an observed position four other surfaces already
        // agree on, not a projected one. A jitter replica clamped down onto it
        // moved today's figure EUR25.71 under the header printing it.
        $firstProjectedDay = $asOf->startOfDay()->addDay();

        foreach ($contributions as $contribution) {
            $converted = $this->convertTriple($contribution, $defaultCurrency, $rates);

            if ($converted === null) {
                $unconverted[$contribution->currency] = true;

                continue;
            }

            [$convertedPoint, $convertedLow, $convertedHigh] = $converted;

            // Half the (low, high) span, taken absolute because the bounds
            // are signed and an expense inverts their order.
            $halfWidth = abs($convertedHigh - $convertedLow) / 2.0;

            $key = self::bucketDateFor($contribution->date, $firstProjectedDay);
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['point_minor' => 0, 'spread_sq' => 0.0];
            }
            $buckets[$key]['point_minor'] += $convertedPoint;
            $buckets[$key]['spread_sq'] += $halfWidth * $halfWidth;
        }

        // Spread does not cumulate across days: a day with no contributions
        // carries the prior spread forward rather than widening.
        /** @var array<string, array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}> $result */
        $result = [];
        $running = $openingBalanceMinor;
        $currentSpread = 0;
        $cursor = $asOf->startOfDay();
        $end = $cursor->addDays($horizonDays);

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            if (isset($buckets[$key])) {
                $running += $buckets[$key]['point_minor'];
                // Only a day that carries uncertainty restates the band. A
                // booked row is certain, and overwriting on it collapsed the
                // band to a single line for the rest of the horizon.
                if ($buckets[$key]['spread_sq'] > 0.0) {
                    $currentSpread = (int) round(sqrt($buckets[$key]['spread_sq']));
                }
            }

            $result[$key] = [
                'date' => $key,
                'low_minor' => $running - $currentSpread,
                'point_minor' => $running,
                'high_minor' => $running + $currentSpread,
                'currency' => $defaultCurrency,
            ];

            $cursor = $cursor->addDay();
        }

        $codes = array_keys($unconverted);
        sort($codes);

        return new DailyFoldResult($result, $codes);
    }

    // Money is moved rather than dropped: the fold never reads a bucket before
    // the walk starts, so a contribution behind it would leave the projection
    // altogether.
    private static function bucketDateFor(CarbonImmutable $date, CarbonImmutable $firstProjectedDay): string
    {
        return ($date->lessThan($firstProjectedDay) ? $firstProjectedDay : $date)->toDateString();
    }

    // All three bounds or none: a triple whose point converted and whose low
    // did not would state a band that does not contain its own estimate.
    /**
     * @param  array<string, string>  $rates
     * @return array{int, int, int}|null the point, low and high in $toCurrency
     */
    private function convertTriple(ForecastContribution $contribution, string $toCurrency, array $rates): ?array
    {
        if ($contribution->currency === $toCurrency) {
            return [$contribution->pointMinor, $contribution->lowMinor, $contribution->highMinor];
        }

        $point = $this->convertMinor($contribution->pointMinor, $contribution->currency, $toCurrency, $rates);
        $low = $this->convertMinor($contribution->lowMinor, $contribution->currency, $toCurrency, $rates);
        $high = $this->convertMinor($contribution->highMinor, $contribution->currency, $toCurrency, $rates);

        if ($point === null || $low === null || $high === null) {
            return null;
        }

        return [$point, $low, $high];
    }

    // Through Money rather than a product of the minor integer and the rate: a
    // rate is major-unit to major-unit, and multiplying minor units by it is
    // only right where both sides hold the same number of them. A yen holds
    // none, and JPY5,000 folded into a euro line as EUR0.30.
    /**
     * @param  array<string, string>  $rates
     */
    private function convertMinor(int $minor, string $fromCurrency, string $toCurrency, array $rates): ?int
    {
        $money = Money::tryOfMinor($minor, $fromCurrency);

        return $money === null ? null : $this->fx->convert($money, $toCurrency, $rates)?->toMinor();
    }
}
