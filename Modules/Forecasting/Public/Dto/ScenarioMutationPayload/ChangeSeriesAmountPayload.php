<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use Modules\Forecasting\Public\Enums\ScenarioMutationKind;

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
        return ScenarioMutationKind::ChangeSeriesAmount->value;
    }
}
