<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

use Carbon\CarbonImmutable;

final readonly class PaymentSettled
{
    /**
     * @param  CarbonImmutable  $dueDate  MUST carry the exact value the corresponding
     *                                    PaymentReminderDue used for this (series, due-date) pair — the notification-side
     *                                    resolver re-derives the same deterministic id from this value, so any drift means
     *                                    the withdrawal can never find its row
     */
    public function __construct(
        public int $userId,
        public int $seriesId,
        public CarbonImmutable $dueDate,
    ) {}
}
