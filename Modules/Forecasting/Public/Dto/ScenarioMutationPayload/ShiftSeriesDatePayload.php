<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;

/**
 * Mutation payload: shift the next due date of a recurring series to
 * a new ISO date. The `scope` field distinguishes between shifting
 * only the next occurrence (`next`) or every subsequent occurrence
 * inside the horizon (`all_subsequent`). The downstream projector
 * inspects the scope to decide whether to keep the original cadence
 * for occurrences past the first shifted one.
 *
 * The constructor validates `scope` is one of the two allowed
 * literals; a typo like 'next_only' would have silently fallen
 * through to `next` (shifting only the first occurrence) even when
 * the user picked "all subsequent".
 */
final class ShiftSeriesDatePayload extends ScenarioMutationPayload
{
    public const ALLOWED_SCOPES = ['next', 'all_subsequent'];

    public function __construct(
        public readonly int $seriesId,
        public readonly string $newNextDate,
        public readonly string $scope,
    ) {
        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException(
                "ShiftSeriesDatePayload.scope must be one of: 'next' | 'all_subsequent'; got '{$scope}'."
            );
        }
    }

    public function kind(): string
    {
        return 'shift_series_date';
    }
}
