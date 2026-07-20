<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class CadenceJitter
{
    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    public function apply(array $contributions, int $jitterDays = 3): array
    {
        if ($contributions === []) {
            return [];
        }

        $window = $jitterDays * 2 + 1;

        // The weight is the fraction of the original contribution
        // attributed to each replica day; integer rounding of the weight
        // is accepted up to ±2 minor units per replica (locked by
        // CadenceJitterTest).
        $weight = 100 / $window;

        $jittered = [];
        foreach ($contributions as $c) {
            for ($offset = -$jitterDays; $offset <= $jitterDays; $offset++) {
                $jittered[] = new ForecastContribution(
                    date: $c->date->addDays($offset),
                    pointMinor: (int) round($c->pointMinor * $weight / 100),
                    lowMinor: (int) round($c->lowMinor * $weight / 100),
                    highMinor: (int) round($c->highMinor * $weight / 100),
                    currency: $c->currency,
                    fxRateUsed: $c->fxRateUsed,
                    seriesId: $c->seriesId,
                    accountId: $c->accountId,
                );
            }
        }

        return $jittered;
    }
}
