<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;

final readonly class PaymentReminderDue
{
    /**
     * @param  CarbonImmutable  $dueDate  the occurrence key — deliberately the DUE date (the
     *                                    series' next_expected_at), never the fire date the job happened to run on, so a
     *                                    later lead-time preference change never fractures an already-delivered reminder
     */
    public function __construct(
        public int $userId,
        public int $seriesId,
        public CarbonImmutable $dueDate,
        public bool $confidenceLow,
        public Money $expectedAmount,
        public string $displayName,
    ) {}
}
