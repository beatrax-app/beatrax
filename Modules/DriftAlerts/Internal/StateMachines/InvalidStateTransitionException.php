<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use RuntimeException;

// Catching this sentinel separately from a generic RuntimeException lets
// callers distinguish a rejected transition from a row that vanished
// mid-flight (a transient cascade delete from a missing recurring_series row).
final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(int $alertId, string $from, string $to): self
    {
        return new self(
            "Illegal drift_alerts transition for id={$alertId}: {$from} -> {$to}",
        );
    }
}
