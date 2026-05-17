<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use RuntimeException;

/**
 * Thrown when RecurringSeriesStateMachine::transition is asked to
 * perform a state change that is not present in the
 * ALLOWED_TRANSITIONS const map.
 *
 * Catching this sentinel separately from a generic RuntimeException
 * lets the queued sweep job + the review-surface actions distinguish
 * "the state machine rejected this transition" (a programming or race
 * condition) from "the row vanished mid-flight" (a transient cascade
 * delete).
 */
final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(int $seriesId, string $from, string $to): self
    {
        return new self(
            "Illegal recurring_series transition for id={$seriesId}: {$from} -> {$to}",
        );
    }
}
