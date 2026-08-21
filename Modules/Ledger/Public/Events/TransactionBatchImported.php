<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Events;

final readonly class TransactionBatchImported
{
    /**
     * @param  list<string>  $sourceFormats
     */
    public function __construct(
        public int $userId,
        public int $insertedCount,
        public array $sourceFormats,
    ) {}
}
