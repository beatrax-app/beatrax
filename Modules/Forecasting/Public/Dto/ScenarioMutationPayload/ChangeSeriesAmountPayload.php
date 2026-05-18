<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

/**
 * Mutation payload: substitute a new amount for every projected
 * occurrence of an existing recurring series inside the horizon.
 * Carries the target series id and the new minor-unit amount (in the
 * series's own latest_currency — the projector inherits the currency
 * from the underlying series row).
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
