<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// What a device holds that a peer sent but the screen has not shown. Beside
// QuarantineReason because it is the same rows read for a different question,
// and it borrows GdkWrapOutcome's vocabulary on purpose. Backed, because a
// Livewire property rehydrates from the client payload with no enum coercion.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#telling-not-yet-openable-apart-from-never-openable-here
 */
enum SyncBacklogState: string
{
    case None = 'none';

    // Received and held in the op log, decodable by this device, not yet
    // written into the tables the screens read. Clears by itself on the next
    // request; the reader is told so it does not read a stale screen as loss.
    case Deferred = 'deferred';

    // Received, and this device holds no key for the epoch it was sealed
    // under. Time alone will not clear it: the key has to arrive from the
    // device that has it, which is a pairing question, not a lock question.
    case AwaitingKey = 'awaiting_key';

    // Whether the wait ends on its own. The two waiting states differ in what
    // the reader can do about them, and a notice that says "unlock" where
    // unlocking cannot help is worse than saying nothing.
    public function clearsWithoutHelp(): bool
    {
        return $this === self::Deferred;
    }
}
