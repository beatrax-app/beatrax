<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * Dispatched for every user-driven mutation to an envelope_assignments row.
 *
 * Mirrors TransactionSplitMutated's contract but targets the envelope
 * assignment table (D-25). SyncCaptureListener's handlers hard-code the
 * target table name per event type, so this dedicated event exists to avoid
 * silently mis-capturing an assignment mutation as a `transactions` op-log
 * row — the same 13.1 D-12a lesson that split off TransactionSplitMutated
 * from TransactionMutated.
 *
 * `assignmentId` is `envelope_assignments.id` — a LOCAL autoincrement
 * surrogate minted by `EnvelopeWriter::setAssigned()` via a raw
 * `insertGetId()` (NOT `updateOrCreate()`). For the common create-once-then-
 * edit lifecycle the pk is stable across subsequent edits on the origin
 * device, so per-(table, pk, field) LWW merge converges for that case.
 *
 * Known convergence limitation (shared with `category_budgets`): two devices
 * that independently create the FIRST assignment for the same
 * `(user_id, category_id, period_start)` while offline mint DISTINCT local
 * pks. On replay those two `create` ops collide on the natural-key UNIQUE
 * constraint `envelope_assignments_user_cat_period_uniq`. This is resolved by
 * the replayer's natural-key upsert/quarantine keyed on
 * `(user_id, category_id, period_start)` — NOT by any pk-stability property of
 * this event. A zero→delete→re-assign cycle likewise mints a fresh pk against
 * the old tombstone. This event asserts no `updateOrCreate` semantics; it only
 * reports which local pk/field changed.
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 */
final readonly class EnvelopeAssignmentMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     *                                             Empty for delete ops.
     */
    public function __construct(
        public int $assignmentId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
