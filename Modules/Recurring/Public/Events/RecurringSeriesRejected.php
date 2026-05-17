<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

/**
 * Dispatched after the `RejectRecurringSeries` action transitions a
 * recurring_series row into `rejected`. Downstream consumers use the
 * event to hide the cluster from the fixed-payments view and to
 * record the user's explicit veto in the audit surface.
 */
final readonly class RecurringSeriesRejected
{
    public function __construct(
        public int $seriesId,
        public int $userId,
    ) {}
}
