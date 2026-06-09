<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Per-account reconciliation header: real balance · allocated · unallocated (D-15).
 *
 * `unallocatedMinor` = realBalanceMinor − allocatedMinor.
 * D-01: unallocated is NEVER stored — always derived at read time.
 * D-02: `unallocatedMinor` may be negative when real-world spending pulls the
 * account balance below the allocated total. `isOverAllocated` signals this
 * condition so the view can apply warning styling.
 *
 * The view renders this header above each account's pot card group.
 */
final class ReconciliationRow extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountName,
        public readonly string $currency,
        public readonly int $realBalanceMinor,
        public readonly int $allocatedMinor,
        public readonly int $unallocatedMinor,   // may be negative (D-02)
        public readonly bool $isOverAllocated,    // true when unallocatedMinor < 0
    ) {}
}
