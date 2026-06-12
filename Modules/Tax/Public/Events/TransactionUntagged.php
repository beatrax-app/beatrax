<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Events;

/**
 * Dispatched after a transaction's tax tag is removed.
 *
 * Consumed by the Tax module's InvalidateNavCounts listener so the sidebar
 * tax_tagged badge refreshes promptly after an untag, and available to any
 * future listener that reacts to tax-tag mutations.
 */
final class TransactionUntagged
{
    public function __construct(
        public readonly int $userId,
        public readonly int $transactionId,
    ) {}
}
