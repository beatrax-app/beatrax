<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Enums\ShiftScope;

/**
 * @see ScenarioMutationPayload
 */
final class ShiftSeriesDatePayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $newNextDate,
        public readonly string $scope,
    ) {
        // A typo like 'next_only' would otherwise silently fall through
        // to 'next' (shifting only the first occurrence) even when the
        // user picked "all subsequent".
        if (ShiftScope::tryFrom($scope) === null) {
            throw new InvalidArgumentException(
                'ShiftSeriesDatePayload.scope must be one of: '.implode(' | ', array_map(static fn (ShiftScope $c): string => "'".$c->value."'", ShiftScope::cases()))."; got '{$scope}'."
            );
        }
    }

    public function kind(): string
    {
        return ScenarioMutationKind::ShiftSeriesDate->value;
    }
}
