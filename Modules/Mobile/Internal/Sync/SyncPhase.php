<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// The backing value is the persisted `mobile_sync_progress.phase` column, so a
// cold-started process reads these strings back out of a row it did not write.
enum SyncPhase: string
{
    case Pending = 'pending';

    case Pulling = 'pulling';

    // Without a state between "transfer finished" and "history rebuilt" the
    // rebuild ran inside the finishing tick and the step never rendered.
    case Rebuilding = 'rebuilding';

    case Complete = 'complete';

    // Rebuilding belongs here with Pulling: it is the slowest step of the
    // initial sync, and a screen that asked only about Pulling dropped its
    // whole progress block for the duration of it.
    public function isInitialSyncInFlight(): bool
    {
        return $this === self::Pulling || $this === self::Rebuilding;
    }

    // A row written by an older build, or by hand, must still hydrate: an
    // unreadable phase resumes the gate from the start rather than throwing.
    public static function fromStorage(mixed $stored): self
    {
        return is_string($stored) ? self::tryFrom($stored) ?? self::Pending : self::Pending;
    }
}
