<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use RuntimeException;

// Thrown when transition() holds a write lock on a row that is no longer
// there. InvalidStateTransitionException names the other half of the pair
// so a caller can tell the two apart; this half arrived as a bare
// RuntimeException until now, identifiable only by its message.
final class SeriesRowVanishedException extends RuntimeException
{
    public static function forSeries(int $seriesId): self
    {
        return new self(
            "RecurringSeriesStateMachine: recurring_series row {$seriesId} not found.",
        );
    }
}
