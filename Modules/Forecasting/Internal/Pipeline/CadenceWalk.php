<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Recurring\Public\Enums\SeriesCadence;

/**
 * @link ../../../../.docs/features/forecasting/projection-math.md#stage-1--one-series-becomes-banded-contributions
 */
final readonly class CadenceWalk
{
    /**
     * @return list<CarbonImmutable>
     */
    public function datesInHorizon(
        CarbonImmutable $anchor,
        SeriesCadence $cadence,
        CarbonImmutable $asOf,
        CarbonImmutable $horizonEnd,
    ): array {
        $dates = [];
        $k = 0;

        // Occurrences increase strictly in k, so the first one past the horizon
        // ends the walk; an anchor behind asOf is stepped over, not dropped.
        while (true) {
            $occurrence = $cadence->occurrenceAt($anchor, $k);
            $k++;
            if ($occurrence === null || $occurrence->greaterThan($horizonEnd)) {
                return $dates;
            }
            if ($occurrence->greaterThanOrEqualTo($asOf)) {
                $dates[] = $occurrence;
            }
        }
    }
}
