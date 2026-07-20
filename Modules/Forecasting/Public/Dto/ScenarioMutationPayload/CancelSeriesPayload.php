<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

/**
 * @see ScenarioMutationPayload
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
