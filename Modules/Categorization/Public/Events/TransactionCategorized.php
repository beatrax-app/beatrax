<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

/**
 * Dispatched after AssignCategory successfully writes (or clears) the
 * category_id on a transaction. Phase 1 has no listener attached; later
 * phases consume this hook (MerchantMemory learning, transfer pairing).
 */
final class TransactionCategorized
{
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $categoryId,
        public readonly int $userId,
    ) {}
}
