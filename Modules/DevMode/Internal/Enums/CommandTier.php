<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Enums;

// Destructive is not unreachable, it is runner-only: the sole way in is
// DestructiveSpawnController, past all three TripleGateModal locks.
enum CommandTier: string
{
    case Safe = 'safe';

    case Destructive = 'destructive';

    // Nothing writes a tier but this enum's own value, so an unreadable one
    // in the run cache or an audit row is an absent entry, not a mislabel.
    public static function fromStored(mixed $stored): self
    {
        return (is_string($stored) ? self::tryFrom($stored) : null) ?? self::Safe;
    }

    public function reachesThePalette(): bool
    {
        return $this === self::Safe;
    }
}
