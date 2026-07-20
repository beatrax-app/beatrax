<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Listeners;

use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

// .csv FileOpenedFromOs intents land here (Import), not in Ingestion —
// filters by extension and persists the validated path into the
// Desktop pending-intent store via the Public RemembersPendingFileIntent
// contract; a re-fired event overwrites the intent (session-scoped, idempotent).
final class HandleFileOpenedFromOs
{
    public function __construct(
        private readonly RemembersPendingFileIntent $intent,
    ) {}

    public function handle(FileOpenedFromOs $event): void
    {
        if ($event->extension !== 'csv') {
            return;
        }
        $this->intent->remember($event->path, $event->extension);
    }
}
