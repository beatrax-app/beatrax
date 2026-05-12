<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

/**
 * Dispatched after AssignCategory successfully writes (or clears) the
 * category_id on a transaction. The event is the public coupling point
 * for downstream consumers (merchant-memory learning, transfer pairing,
 * notifications) that want to react to a category change without
 * importing Categorization internals.
 */
final class TransactionCategorized
{
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $categoryId,
        public readonly int $userId,
    ) {}
}
