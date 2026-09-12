<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// The columns saying when a source filed a transaction and which occurrence of
// its file the row is: the terms of the dedup tuple no reader has an opinion
// about. A source states them and a reader handed two has no ground to choose,
// so they move as a set, from the source, or the row disagrees with its key.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#a-reference-the-ledger-already-holds
 */
final class TransactionBooking extends Data
{
    public function __construct(
        public readonly string $postedAt,
        public readonly string $bookedAt,
        public readonly string $valueDate,
        public readonly int $occurrenceOrdinal,
    ) {}

    // Formatted at the scale each column stores, not carried as dates: a DATE
    // column compared against a datetime string matches nothing, and the
    // fingerprint is composed over the same two spellings.
    public static function of(CanonicalTransaction $tx): self
    {
        return new self(
            postedAt: $tx->postedAt->toDateString(),
            bookedAt: $tx->bookedAt->toDateTimeString(),
            valueDate: $tx->valueDate->toDateString(),
            occurrenceOrdinal: $tx->occurrenceOrdinal,
        );
    }

    /**
     * @return array{posted_at: string, booked_at: string, value_date: string, occurrence_ordinal: int}
     */
    public function toColumns(): array
    {
        return [
            'posted_at' => $this->postedAt,
            'booked_at' => $this->bookedAt,
            'value_date' => $this->valueDate,
            'occurrence_ordinal' => $this->occurrenceOrdinal,
        ];
    }
}
