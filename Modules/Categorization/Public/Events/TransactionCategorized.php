<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

// The public coupling point for downstream consumers (merchant-memory
// learning, transfer pairing, notifications) that want to react to a
// category change without importing Categorization internals.
final class TransactionCategorized
{
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $categoryId,
        public readonly int $userId,
    ) {}
}
