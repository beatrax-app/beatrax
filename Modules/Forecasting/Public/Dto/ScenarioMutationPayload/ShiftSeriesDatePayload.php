<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

/**
 * Mutation payload: shift the next due date of a recurring series to
 * a new ISO date. The `scope` field distinguishes between shifting
 * only the next occurrence (`next`) or every subsequent occurrence
 * inside the horizon (`all_subsequent`). The downstream projector
 * inspects the scope to decide whether to keep the original cadence
 * for occurrences past the first shifted one.
 */
final class ShiftSeriesDatePayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $newNextDate,
        public readonly string $scope,
    ) {}

    public function kind(): string
    {
        return 'shift_series_date';
    }
}
