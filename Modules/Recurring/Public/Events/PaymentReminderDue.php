<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * Dispatched by `EmitPaymentRemindersJob` for a recurring series whose
 * expected next charge falls inside the device's lead-time window (Req 4).
 *
 * `$dueDate` is D-06's occurrence key — deliberately the DUE date (the
 * series' `next_expected_at`), never the fire date the job happened to run
 * on. A later change to the lead-time preference must never fracture an
 * already-delivered reminder: the same due date always derives the same
 * notification id regardless of which day the job actually emitted it.
 *
 * `final readonly` clones `SavedReportMutated`'s minimal constructor-only
 * shape — this module dispatches the Public event and knows nothing about
 * the notification store (D-28); the notification-side listener imports
 * this event, never the other way around.
 */
final readonly class PaymentReminderDue
{
    public function __construct(
        public int $userId,
        public int $seriesId,
        public CarbonImmutable $dueDate,
        public bool $confidenceLow,
        public Money $expectedAmount,
        public string $displayName,
    ) {}
}
