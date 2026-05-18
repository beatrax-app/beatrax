<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

/**
 * Mutation payload: cancel an approved recurring series for the
 * duration of the projection. Carries only the target series id; the
 * downstream projector skips every projected occurrence of the series
 * within the horizon.
 */
final class CancelSeriesPayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly int $seriesId,
    ) {}

    public function kind(): string
    {
        return 'cancel_series';
    }
}
