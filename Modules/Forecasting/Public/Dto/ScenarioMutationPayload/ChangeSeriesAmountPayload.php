<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

/**
 * @see ScenarioMutationPayload
 */
final class ChangeSeriesAmountPayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly int $seriesId,
        public readonly int $newAmountMinor,
    ) {}

    public function kind(): string
    {
        return 'change_series_amount';
    }
}
