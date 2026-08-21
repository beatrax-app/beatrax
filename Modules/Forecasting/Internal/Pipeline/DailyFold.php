<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

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
        // spread_sq accumulates half-widths squared: same-day uncertainties
        // combine in quadrature, not by addition.
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

            // Half the (low, high) span, taken absolute because the bounds
            // are signed and an expense inverts their order.
            $halfWidth = abs($convertedHigh - $convertedLow) / 2.0;

            $key = $contribution->date->toDateString();
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
