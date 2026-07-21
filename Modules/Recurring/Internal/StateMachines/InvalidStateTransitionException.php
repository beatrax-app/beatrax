<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use RuntimeException;

// Thrown when RecurringSeriesStateMachine::transition() is asked to
// perform a state change absent from ALLOWED_TRANSITIONS; catching this
// sentinel separately from a generic RuntimeException lets callers
// distinguish a rejected transition from a row that vanished mid-flight.
final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(int $seriesId, string $from, string $to): self
    {
        return new self(
            "Illegal recurring_series transition for id={$seriesId}: {$from} -> {$to}",
        );
    }
}
