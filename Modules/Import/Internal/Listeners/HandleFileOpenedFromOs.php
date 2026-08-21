<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Listeners;

use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

// .csv FileOpenedFromOs intents land here, not in Ingestion. A re-fired
// event overwrites the stored intent, which is session-scoped.
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
