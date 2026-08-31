<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// The one deliberate difference between the two readers of the op log, kept as
// a choice the caller makes rather than as a second row-to-entry mapper.
enum UnknownOpTypePolicy
{
    // A rebuild reads rows this device itself wrote, so an op_type nothing can
    // name is corruption and must not be replayed past.
    case Fail;

    // An exchange reads rows about to be handed to a peer, and an op the peer
    // could never replay is dropped rather than aborting the whole exchange.
    case Skip;

    public function resolve(string $opType): ?OpType
    {
        return match ($this) {
            self::Fail => OpType::from($opType),
            self::Skip => OpType::tryFrom($opType),
        };
    }
}
