<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * Dispatched for every user-driven mutation to a transactions row.
 *
 * NOT fired for import-pipeline writes (those are immutable after ingestion)
 * nor for GC/background pipeline writes (e.g. ResolveCounterpartyStage).
 * Only user-facing edit paths hand-wire this dispatch (D-02).
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 *
 * @param  array<string, mixed>  $dirtyFields  Changed field → new-value map.
 *                                             Empty for DeleteTombstone ops.
 * @param  string  $mutationType  'edit' | 'create' | 'delete'
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
