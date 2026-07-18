<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

use Carbon\CarbonImmutable;

/**
 * Dispatched when an expected recurring charge is matched to a real
 * transaction — Req 13's post-delivery withdrawal signal.
 *
 * `$dueDate` MUST carry the exact same value the corresponding
 * `PaymentReminderDue` used for this (series, due-date) pair. The
 * notification-side `ResolveSettledReminder` listener re-derives the SAME
 * deterministic notification id from
 * `(userId, 'payment_reminder', seriesId, dueDate)` via
 * `DeterministicKeyDeriver`; if `$dueDate` drifts from the value the
 * reminder used, the resolver can never find the row it is meant to
 * withdraw.
 *
 * `final readonly` mirrors `PaymentReminderDue` / `SavedReportMutated`'s
 * minimal constructor-only shape.
 */
final readonly class PaymentSettled
{
    public function __construct(
        public int $userId,
        public int $seriesId,
        public CarbonImmutable $dueDate,
    ) {}
}
