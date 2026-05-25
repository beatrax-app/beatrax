<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Events;

/**
 * Event payload dispatched when an inbox's OAuth token refresh fails in
 * a way that requires the user to re-grant consent at the provider.
 *
 * The event sits in the EmailScan module's Public namespace because the
 * Banner / SystemAlerts surface lives in the Core module — the cross-
 * module hand-off goes through this immutable DTO so neither side
 * reaches into the other's internals.
 *
 * `provider` is one of 'gmail' or 'microsoft'.
 */
final class InboxTokenFailed
{
    public function __construct(
        public readonly int $inboxId,
        public readonly int $userId,
        public readonly string $provider,
    ) {}
}
