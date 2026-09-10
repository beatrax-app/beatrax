<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

// The columns a transaction is deduplicated on, as one value. The sweeps that
// rewrite counterparty_normalized reach these off a row rather than rebuilding
// a whole CanonicalTransaction for eight of its fields, and naming the set is
// what keeps the group and the tuple it completes derived from one list.
final readonly class FingerprintTuple
{
    public function __construct(
        public int $userId,
        public int $accountId,
        public string $postedAtDate,
        public string $bookedAtDateTime,
        public int $amountMinor,
        public string $currency,
        public string $counterpartyNormalized,
        public int $occurrenceOrdinal,
    ) {}

    public static function of(CanonicalTransaction $tx): self
    {
        return new self(
            $tx->userId ?? 0,
            $tx->accountId,
            $tx->postedAt->toDateString(),
            $tx->bookedAt->toDateTimeString(),
            $tx->amountMinor,
            $tx->currency,
            $tx->counterpartyNormalized,
            $tx->occurrenceOrdinal,
        );
    }
}
