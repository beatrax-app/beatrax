<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Outcome of a `RecordTransactions` batch: how many new rows landed and how
 * many were silently dropped because their fingerprint already existed.
 */
final class RecordResult extends Data
{
    public function __construct(
        public readonly int $inserted,
        public readonly int $duplicates,
    ) {}
}
