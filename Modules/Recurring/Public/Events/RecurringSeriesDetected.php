<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

/**
 * Dispatched the first time a sweep run promotes a candidate cluster
 * into a `pending` recurring_series row.
 *
 * Downstream consumers listen to surface the top-nav badge and to
 * schedule the user-facing review prompt without coupling to the
 * detector internals.
 */
final readonly class RecurringSeriesDetected
{
    public function __construct(
        public int $seriesId,
        public int $userId,
        public string $direction,
        public string $detectedName,
        public string $cadence,
    ) {}
}
