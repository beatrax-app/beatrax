<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Services\CrossCurrencyTotal;

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
        $equalDays = array_fill(0, max($jitterDays * 2 + 1, 0), 1);

        $jittered = [];
        foreach ($contributions as $c) {
            if (! $c->dateIsUncertain) {
                $jittered[] = $c;

                continue;
            }

            foreach (self::replicasOf($c, $equalDays, $jitterDays, $windowStart, $windowEnd) as $replica) {
                $jittered[] = $replica;
            }
        }

        return $jittered;
    }

    // A replica is a share of one occurrence, so the shares have to add back up
    // to it: rounding each seventh on its own turned EUR 10.00 into EUR 10.03
    // and made a single cent disappear altogether.
    /**
     * @param  list<int>  $equalDays
     * @return list<ForecastContribution>
     */
    private static function replicasOf(
        ForecastContribution $c,
        array $equalDays,
        int $jitterDays,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        $point = self::shares($c->pointMinor, $equalDays);
        $low = self::shares($c->lowMinor, $equalDays);
        $high = self::shares($c->highMinor, $equalDays);

        $replicas = [];
        foreach ($point as $index => $pointMinor) {
            $replicas[] = new ForecastContribution(
                date: self::clampToWindow($c->date->addDays($index - $jitterDays), $windowStart, $windowEnd),
                pointMinor: $pointMinor,
                lowMinor: $low[$index] ?? 0,
                highMinor: $high[$index] ?? 0,
                currency: $c->currency,
                seriesId: $c->seriesId,
                accountId: $c->accountId,
            );
        }

        return $replicas;
    }

    /**
     * @param  list<int>  $equalDays
     * @return list<int>
     */
    private static function shares(int $wholeMinor, array $equalDays): array
    {
        $shares = CrossCurrencyTotal::apportion($wholeMinor, $equalDays);

        return $shares === null ? array_fill(0, count($equalDays), 0) : array_values($shares);
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
