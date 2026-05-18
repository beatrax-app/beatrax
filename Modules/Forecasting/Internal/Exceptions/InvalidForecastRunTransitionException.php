<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Exceptions;

use RuntimeException;

/**
 * Thrown when `ForecastRunStateMachine` is asked to perform a status
 * change that is not present in its locked transition map.
 *
 * Catching this sentinel separately from a generic RuntimeException
 * lets the queued projector job + the run-lookup query distinguish
 * "the state machine rejected this transition" (a programming or race
 * condition) from "the row vanished mid-flight" (a transient cascade
 * delete from a missing scenario row).
 */
final class InvalidForecastRunTransitionException extends RuntimeException
{
    public static function forTransition(int $runId, string $from, string $to): self
    {
        return new self(
            "Illegal forecast_runs transition for id={$runId}: {$from} -> {$to}",
        );
    }
}
