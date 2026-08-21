<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use RuntimeException;

// Thrown when transition() holds a write lock on a row that has since vanished;
// InvalidStateTransitionException is the other half of the pair.
final class SeriesRowVanishedException extends RuntimeException
{
    public static function forSeries(int $seriesId): self
    {
        return new self(
            "RecurringSeriesStateMachine: recurring_series row {$seriesId} not found.",
        );
    }
}
