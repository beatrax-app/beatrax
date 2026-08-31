<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Events;

// Public rather than Internal because the Banner/SystemAlerts surface that
// consumes it lives in Core. $provider is 'gmail' or 'microsoft'.
final readonly class InboxTokenFailed
{
    public function __construct(
        public int $inboxId,
        public int $userId,
        public string $provider,
    ) {}
}
