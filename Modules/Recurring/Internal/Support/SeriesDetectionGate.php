<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

use Modules\Recurring\Public\Enums\RecurringSeriesState;

// What the expense and the income pass must agree on to produce one series
// set: how many sightings make a series, how far an amount may move before it
// is drift, and which states honour a stored tolerance. Each detector declared
// all three, byte-identically, and the column default restated one a third time.
/**
 * @link ../../../../.docs/features/recurring/series-detection.md
 */
final class SeriesDetectionGate
{
    public const int MIN_OCCURRENCES = 2;

    public const int DEFAULT_VARIANCE_TOLERANCE_PERCENT = 25;

    /** @var list<string> */
    public const array TOLERANCE_STATES = [
        RecurringSeriesState::Pending->value,
        RecurringSeriesState::Approved->value,
        RecurringSeriesState::CadenceChanged->value,
        RecurringSeriesState::Snoozed->value,
    ];
}
