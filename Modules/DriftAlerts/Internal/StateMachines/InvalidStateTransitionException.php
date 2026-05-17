<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use RuntimeException;

/**
 * Thrown when `DriftAlertStateMachine::transition` is asked to perform
 * a state change that is not present in the `ALLOWED_TRANSITIONS`
 * const map.
 *
 * Catching this sentinel separately from a generic RuntimeException
 * lets the queued evaluator job + the drift-page actions distinguish
 * "the state machine rejected this transition" (a programming or race
 * condition) from "the row vanished mid-flight" (a transient cascade
 * delete from a missing recurring_series row).
 */
final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(int $alertId, string $from, string $to): self
    {
        return new self(
            "Illegal drift_alerts transition for id={$alertId}: {$from} -> {$to}",
        );
    }
}
