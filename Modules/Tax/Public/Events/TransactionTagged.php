<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Events;

final readonly class TransactionTagged
{
    public function __construct(
        public int $userId,
        public int $transactionId,
        public ?int $deductionCategoryId,
    ) {}
}
