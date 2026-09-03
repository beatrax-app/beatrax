<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

final readonly class TransactionSplitMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit'. A removed leg is a row
     *                                its transaction owns, announced as an
     *                                EntityMutated delete like every other.
     * @param  array<string, mixed>  $dirtyFields  Changed field → new-value map.
     */
    public function __construct(
        public int $splitId,
        public int $transactionId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
