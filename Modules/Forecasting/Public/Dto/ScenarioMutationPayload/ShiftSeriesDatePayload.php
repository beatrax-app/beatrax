<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;

/**
 * @see ScenarioMutationPayload
 */
final class ShiftSeriesDatePayload extends ScenarioMutationPayload
{
    public const ALLOWED_SCOPES = ['next', 'all_subsequent'];

    public function __construct(
        public readonly int $seriesId,
        public readonly string $newNextDate,
        public readonly string $scope,
    ) {
        // A typo like 'next_only' would otherwise silently fall through
        // to 'next' (shifting only the first occurrence) even when the
        // user picked "all subsequent".
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
