<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Events;

// Dispatched after a transaction is tagged or re-tagged; consumed by
// InvalidateNavCounts (sidebar tax_tagged badge cache). deductionCategoryId
// is null when a tag is applied without a category.
final class TransactionTagged
{
    public function __construct(
        public readonly int $userId,
        public readonly int $transactionId,
        public readonly ?int $deductionCategoryId,
    ) {}
}
