<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\StateMachines;

use RuntimeException;

// Catching this sentinel separately from a generic RuntimeException lets
// callers distinguish "the state machine rejected this transition" from
// "the row vanished mid-flight" (a cascade delete from a deleted
// transaction).
final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(int $alertId, string $from, string $to): self
    {
        return new self(
            "Illegal anomaly_alerts transition for id={$alertId}: {$from} -> {$to}",
        );
    }
}
