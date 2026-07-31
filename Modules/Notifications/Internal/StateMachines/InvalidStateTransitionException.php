<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\StateMachines;

use RuntimeException;

// Catching this sentinel separately from a generic RuntimeException lets
// callers distinguish "the state machine rejected this transition" (the
// only legal edge is open -> resolved) from "the notifications row
// vanished mid-flight".
/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(string $id, string $currentState, string $toState): self
    {
        return new self(
            "Illegal notifications transition for id={$id}: {$currentState} -> {$toState}",
        );
    }
}
