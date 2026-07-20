<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class TransactionMutated
{
    /**
     * @param  array<string, mixed>  $dirtyFields
     */
    public function __construct(
        public int $transactionId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
