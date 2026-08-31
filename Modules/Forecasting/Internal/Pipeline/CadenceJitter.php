<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/forecasting/projection-math.md#cadence-jitter
 */
final readonly class CadenceJitter
{
    // A half-width, not a window width: the replicas span D-3 through D+3,
    // which is 2N+1 = 7 days.
    public const int WINDOW_DAYS = 3;

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    public function apply(
        array $contributions,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        int $jitterDays = self::WINDOW_DAYS,
    ): array {
        $window = $jitterDays * 2 + 1;

        // The weight is the fraction of the original contribution
        // attributed to each replica day; integer rounding of the weight
        // is accepted up to ±2 minor units per replica (locked by
        // CadenceJitterTest).
        $weight = 100 / $window;

        $jittered = [];
        foreach ($contributions as $c) {
            if (! $c->dateIsUncertain) {
                $jittered[] = $c;

                continue;
            }

            for ($offset = -$jitterDays; $offset <= $jitterDays; $offset++) {
                $jittered[] = new ForecastContribution(
                    date: self::clampToWindow($c->date->addDays($offset), $windowStart, $windowEnd),
                    pointMinor: (int) round($c->pointMinor * $weight / 100),
                    lowMinor: (int) round($c->lowMinor * $weight / 100),
                    highMinor: (int) round($c->highMinor * $weight / 100),
                    currency: $c->currency,
                    seriesId: $c->seriesId,
                    accountId: $c->accountId,
                );
            }
        }

        return $jittered;
    }

    // The fold walks [windowStart, windowEnd] and never reads a bucket outside
    // it, so a replica dated past either end takes its share of the occurrence
    // out of the projection with it. The boundary day carries that share.
    private static function clampToWindow(
        CarbonImmutable $date,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): CarbonImmutable {
        if ($date->lessThan($windowStart)) {
            return $windowStart;
        }

        return $date->greaterThan($windowEnd) ? $windowEnd : $date;
    }
}
