<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// The one answer the status surface gives across every paired device. Backed
// for the reason SyncBacklogState is: the value rides a Livewire property, and
// a property rehydrates from the client payload with no enum coercion.
enum SyncOverallStatus: string
{
    case Unknown = 'unknown';

    case Error = 'error';

    case Syncing = 'syncing';

    case Offline = 'offline';

    // A peer is holding entries back for an author this device cannot verify.
    // Not a failure and not an exchange: the history is intact on the device
    // that holds it, and only the reader confirming that author releases it.
    case Withheld = 'withheld';

    // Changes made after the last session closed, with nothing in flight to
    // carry them. This state used to borrow "syncing", which told the reader an
    // exchange was in progress while nothing at all was connected.
    case Behind = 'behind';

    case AllSynced = 'all_synced';

    // Written here rather than at the call site so a case added without copy is
    // a key that resolves to itself, which the call-site guard catches, instead
    // of a screen quietly showing a neighbouring case's sentence.
    public function labelKey(): string
    {
        return match ($this) {
            self::Unknown => 'sync::status.not_synced_yet',
            self::Error => 'sync::status.error',
            self::Syncing => 'sync::status.syncing',
            self::Offline => 'sync::status.offline',
            self::Withheld => 'sync::status.withheld',
            self::Behind => 'sync::status.behind',
            self::AllSynced => 'sync::status.all_synced',
        };
    }
}
