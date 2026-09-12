<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Ledger\Public\Dto\TransactionBooking;
use Spatie\LaravelData\Data;

// One row a restatement moved, carried out of the write rather than read back
// off the row after it: a second restatement in the same confirm may move that
// row again, and an announcement composed from a re-read would state the last
// row's terms under every stamp.
final class AdoptedBooking extends Data
{
    public function __construct(
        public readonly int $transactionId,
        public readonly TransactionBooking $booking,
    ) {}
}
