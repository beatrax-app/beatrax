<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Fold per-occurrence `ForecastContribution`s into a per-day running
 * balance with a confidence spread.
 *
 * Combines per-occurrence spreads via quadrature (`√(Σ spread²)`).
 * Statistically correct for INDEPENDENT series — if two series share
 * an underlying cause (for example two streaming subscriptions on the
 * same billing cycle), this UNDER-estimates the combined spread.
 * Phase 10 treats every approved recurring series as independent; the
 * percentile tier (Wave 5) sidesteps the assumption by reading the
 * observed empirical distribution per series. Reference:
 * Cornell 8.04 / MIT OCW 6.012.
 *
 * Cross-currency contributions are converted to `$defaultCurrency` at
 * fold time using the contribution's stored `fxRateUsed`. A contribution
 * whose currency differs from the account default but whose `fxRateUsed`
 * is null raises `InvalidArgumentException` — Phase 8 D-840 guarantees
 * every cross-currency occurrence carries a stored fx rate, so a null
 * here is a real data corruption that must surface rather than silently
 * leak a USD point into an EUR running balance.
 *
 * Pure-math class — no DI, no DB reads, no clock.
 */
final readonly class DailyFold
{
    /**
     * @param  list<ForecastContribution>  $contributions
     * @return array<string, array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>
     */
    public function fold(
        int $openingBalanceMinor,
        array $contributions,
        CarbonImmutable $asOf,
        int $horizonDays,
        string $defaultCurrency,
    ): array {
        // Pre-aggregate contributions per Y-m-d date, converting to the
        // default currency on the way in. `point` is the signed sum;
        // `spread_sq` is the sum of half-width-squared per the quadrature
        // formula.
        /** @var array<string, array{point_minor: int, spread_sq: float}> $buckets */
        $buckets = [];

        foreach ($contributions as $contribution) {
            $convertedPoint = $this->convertMinor(
                $contribution->pointMinor,
                $contribution->currency,
                $contribution->fxRateUsed,
                $defaultCurrency,
            );
            $convertedLow = $this->convertMinor(
                $contribution->lowMinor,
                $contribution->currency,
                $contribution->fxRateUsed,
                $defaultCurrency,
            );
            $convertedHigh = $this->convertMinor(
                $contribution->highMinor,
                $contribution->currency,
                $contribution->fxRateUsed,
                $defaultCurrency,
            );

            // Half-width of the (low, high) interval. The interval is
            // signed; the half-width is always the absolute distance
            // from the point to either bound, divided by two of the
            // total span.
            $halfWidth = abs($convertedHigh - $convertedLow) / 2.0;

            $key = $contribution->date->toDateString();
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['point_minor' => 0, 'spread_sq' => 0.0];
            }
            $buckets[$key]['point_minor'] += $convertedPoint;
            $buckets[$key]['spread_sq'] += $halfWidth * $halfWidth;
        }

        // Walk each day in the horizon range from asOf through asOf +
        // horizonDays inclusive. Days with no contributions carry the
        // running balance and the cumulative spread forward unchanged
        // so the resulting series is continuous (no missing dates → no
        // chart gaps).
        /** @var array<string, array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}> $result */
        $result = [];
        $running = $openingBalanceMinor;
        $cumSpreadSq = 0.0;
        $cursor = $asOf->startOfDay();
        $end = $cursor->addDays($horizonDays);

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            if (isset($buckets[$key])) {
                $running += $buckets[$key]['point_minor'];
                $cumSpreadSq += $buckets[$key]['spread_sq'];
            }

            $spread = (int) round(sqrt($cumSpreadSq));

            $result[$key] = [
                'date' => $key,
                'low_minor' => $running - $spread,
                'point_minor' => $running,
                'high_minor' => $running + $spread,
                'currency' => $defaultCurrency,
            ];

            $cursor = $cursor->addDay();
        }

        return $result;
    }

    private function convertMinor(int $minor, string $fromCurrency, ?float $fxRateUsed, string $toCurrency): int
    {
        if ($fromCurrency === $toCurrency) {
            return $minor;
        }
        if ($fxRateUsed === null) {
            throw new InvalidArgumentException(sprintf(
                'DailyFold: cross-currency contribution from %s to %s is missing fxRateUsed.',
                $fromCurrency,
                $toCurrency,
            ));
        }

        return (int) round($minor * $fxRateUsed);
    }
}
