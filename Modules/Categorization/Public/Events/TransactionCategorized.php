<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

final class TransactionCategorized
{
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $categoryId,
        public readonly int $userId,
    ) {}
}
