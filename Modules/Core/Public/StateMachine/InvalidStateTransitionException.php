<?php

declare(strict_types=1);

namespace Modules\Core\Public\StateMachine;

use RuntimeException;

// Catching this sentinel separately from a generic RuntimeException lets a
// caller distinguish "the state machine rejected this transition" from "the
// row vanished mid-flight". The $subject is the table the machine guards.
final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(string $subject, int $id, string $from, string $to): self
    {
        return new self("Illegal {$subject} transition for id={$id}: {$from} -> {$to}");
    }
}
