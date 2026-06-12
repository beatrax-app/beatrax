<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Events;

/**
 * Dispatched after a transaction is successfully tagged or re-tagged.
 *
 * Consumed by badge surfaces, the dashboard summary card, and any
 * future listener that reacts to tax-tag mutations. The deduction
 * category id is null when a tag is applied without a category
 * (uncategorised tag).
 */
final class TransactionTagged
{
    public function __construct(
        public readonly int $userId,
        public readonly int $transactionId,
        public readonly ?int $deductionCategoryId,
    ) {}
}
