<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Events;

final class TransactionUntagged
{
    public function __construct(
        public readonly int $userId,
        public readonly int $transactionId,
    ) {}
}
