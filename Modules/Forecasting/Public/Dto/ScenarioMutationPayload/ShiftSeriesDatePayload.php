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
    public readonly string $newNextDate;

    public function __construct(
        public readonly int $seriesId,
        string $newNextDate,
        public readonly string $scope,
    ) {
        $this->newNextDate = self::assertCalendarDay($newNextDate, 'newNextDate');
        // ScenarioApplier only tests for 'all_subsequent', so any other value
        // collapses to shifting the first occurrence alone.
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
