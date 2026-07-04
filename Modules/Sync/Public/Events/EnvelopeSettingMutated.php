<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * Dispatched for every user-driven mutation to an envelope_settings row.
 *
 * Mirrors TransactionSplitMutated's contract but targets the per-envelope
 * overspend-mode settings table (D-25). SyncCaptureListener's handlers
 * hard-code the target table name per event type, so this dedicated event
 * exists to avoid silently mis-capturing a settings mutation as a
 * `transactions` op-log row.
 *
 * `settingId` is `envelope_settings.id` — a LOCAL autoincrement surrogate
 * created by `EnvelopeWriter::setOverspendMode()` via an Eloquent `create()`
 * (NOT `updateOrCreate()`). For the common create-once-then-edit lifecycle the
 * pk is stable across subsequent edits on the origin device, so
 * per-(table, pk, field) LWW merge converges for that case.
 *
 * Known convergence limitation (shared with `category_budgets`): two devices
 * that independently create the FIRST settings row for the same
 * `(user_id, category_id)` while offline mint DISTINCT local pks whose
 * `create` ops collide on that natural-key UNIQUE constraint at replay. This
 * is resolved by the replayer's natural-key upsert/quarantine keyed on
 * `(user_id, category_id)` — NOT by any pk-stability property of this event.
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 */
final readonly class EnvelopeSettingMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     *                                             Empty for delete ops.
     */
    public function __construct(
        public int $settingId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
