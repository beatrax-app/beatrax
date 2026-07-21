<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Events;

// Dispatched when an inbox's OAuth token refresh fails in a way that
// requires the user to re-grant consent. Lives in EmailScan's Public
// namespace since the Banner/SystemAlerts surface lives in Core.
// provider is one of 'gmail' or 'microsoft'.
final class InboxTokenFailed
{
    public function __construct(
        public readonly int $inboxId,
        public readonly int $userId,
        public readonly string $provider,
    ) {}
}
