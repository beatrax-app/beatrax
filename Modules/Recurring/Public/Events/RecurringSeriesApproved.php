<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

/**
 * Dispatched after the `ApproveRecurringSeries` action successfully
 * promotes a recurring_series row from `pending` (or `cadence_changed`
 * or `snoozed`) to `approved`.
 */
final readonly class RecurringSeriesApproved
{
    public function __construct(
        public int $seriesId,
        public int $userId,
    ) {}
}
