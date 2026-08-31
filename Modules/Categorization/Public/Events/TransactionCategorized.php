<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

final readonly class TransactionCategorized
{
    public function __construct(
        public int $transactionId,
        public ?int $categoryId,
        public int $userId,
    ) {}
}
