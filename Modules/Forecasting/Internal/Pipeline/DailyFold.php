<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
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
        // horizonDays inclusive. The per-day spread combines same-day
        // contributions via quadrature but does NOT cumulate across days
        // — a day without contributions carries the prior spread forward.
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
                $currentSpread = (int) round(sqrt($buckets[$key]['spread_sq']));
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
