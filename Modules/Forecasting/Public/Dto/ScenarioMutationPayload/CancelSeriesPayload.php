<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use Modules\Forecasting\Public\Enums\ScenarioMutationKind;

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
        return ScenarioMutationKind::CancelSeries->value;
    }
}
