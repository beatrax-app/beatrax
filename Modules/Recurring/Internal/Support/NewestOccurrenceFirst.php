<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

// An occurrence id is minted, not counted: OccurrenceWriter takes random_int
// over the whole integer range, so `observed_at DESC, id DESC` settled a
// same-day tie at random — and settled it differently on each paired device.
// What both devices read off the one statement is the charge itself.
/**
 * @link ../../../../.docs/features/recurring/series-detection.md#which-occurrence-is-the-newest
 */
final class NewestOccurrenceFirst
{
    // booked_at carries the time of day the date column drops; the amount
    // parts two charges of one minute; the ordinal parts the rows even that
    // cannot, because it is counted over the statement file both devices read.
    public const string SQL = 'o.observed_at desc, t.booked_at desc, o.observed_amount_minor desc, t.occurrence_ordinal desc';
}
