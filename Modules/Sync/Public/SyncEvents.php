<?php

declare(strict_types=1);

namespace Modules\Sync\Public;

// The dispatcher (Mobile's sync screen) and the #[On] listener (Sync's status
// section) sit in different modules; this is the shared name.
final class SyncEvents
{
    public const string COMPLETED = 'sync-completed';
}
