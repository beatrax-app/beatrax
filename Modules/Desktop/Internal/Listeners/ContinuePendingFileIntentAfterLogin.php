<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\PendingFileIntent;

// The pending file-open intent is session-scoped: a user logging in on
// a different session never inherits a prior user's intent, and a
// stale intent (the file was deleted/unmounted between double-click
// and login) auto-discards on read.
final class ContinuePendingFileIntentAfterLogin
{
    public function __construct(
        private readonly PendingFileIntent $intent,
    ) {}

    public function handle(): void
    {
        // Reading here — for its realpath()/is_file() side effect —
        // guarantees the next /desktop/file-staging navigation either
        // finds a still-valid intent or finds nothing, never a stale
        // row pointing at a vanished file.
        $this->intent->pending();
    }
}
