<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Events;

use Carbon\CarbonImmutable;

/**
 * Dispatched by `DetectIcsStatementReadyJob` when an `inbox_messages` row's
 * `sender_email`/`subject` match the tunable ICS statement-ready pattern
 * (Req 14, D-14/D-15).
 *
 * Metadata-only by construction: the detector's entire input surface is
 * `sender_email`/`subject` (plaintext columns populated at fetch time), so
 * this event structurally cannot carry transaction data — none was ever
 * read from the message body.
 *
 * `internalDate` is `Modules\Notifications\Internal\Listeners\PersistIcsStatementReady`'s
 * occurrence-key source (`->format('Y-m')`) — the calendar month the
 * statement-ready email arrived, deliberately NOT the message id, so a
 * bank-side resend within the same month collapses to one notification
 * rather than fracturing into a second (D-15's "fires once per statement,
 * not per message" — 19-RESEARCH.md Pitfall 4).
 *
 * `final readonly` mirrors `Modules\Recurring\Public\Events\PaymentReminderDue`'s
 * minimal constructor-only shape — EmailScan dispatches this Public event
 * and knows nothing about the notification store (D-28); the
 * Notifications-side listener imports this event, never the other way
 * around.
 */
final readonly class IcsStatementReady
{
    public function __construct(
        public int $userId,
        public int $messageId,
        public CarbonImmutable $internalDate,
    ) {}
}
