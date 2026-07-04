<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * Dispatched for every user-driven mutation to an envelope_moves row.
 *
 * Mirrors TransactionSplitMutated's contract but targets the envelope moves
 * ledger (D-25). A move writes a paired debit/credit row in one DB
 * transaction (D-02); each row dispatches its own EnvelopeMoveMutated with
 * `mutationType: 'create'` so both rows are individually captured to the
 * op-log. Undo hard-deletes both paired rows and dispatches
 * `mutationType: 'delete'` for EACH row's own pk (D-07 — tombstone, not a
 * reversing pair).
 *
 * `moveId` is the stable `envelope_moves.id` primary key of ONE row in the
 * pair — never the pair as a whole, since op-log capture is per-row.
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 */
final readonly class EnvelopeMoveMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     *                                             Empty for delete ops.
     */
    public function __construct(
        public int $moveId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
