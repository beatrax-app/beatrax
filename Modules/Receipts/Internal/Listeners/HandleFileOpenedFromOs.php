<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Listeners;

use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

// Routes a .eml FileOpenedFromOs intent into the Receipts flow: filters
// to the eml extension and persists the validated path into Desktop's
// session-scoped pending-intent store via its Public contract only —
// never reaching into Modules\Desktop\Internal directly.
final readonly class HandleFileOpenedFromOs
{
    public function __construct(
        private RemembersPendingFileIntent $intent,
    ) {}

    public function handle(FileOpenedFromOs $event): void
    {
        if ($event->extension !== 'eml') {
            return;
        }
        $this->intent->remember($event->path, $event->extension);
    }
}
