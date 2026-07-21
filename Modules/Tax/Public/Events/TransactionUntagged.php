<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Events;

// Dispatched after a transaction's tax tag is removed; consumed by
// InvalidateNavCounts so the sidebar tax_tagged badge refreshes promptly.
final class TransactionUntagged
{
    public function __construct(
        public readonly int $userId,
        public readonly int $transactionId,
    ) {}
}
