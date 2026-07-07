<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * Dispatched for every user-driven mutation to a saved_reports row (Req 9/10,
 * D-02).
 *
 * Mirrors EnvelopeSettingMutated's contract but targets the user's saved
 * report definitions + dashboard pin state. SyncCaptureListener's handlers
 * hard-code the target table name per event type, so this dedicated event
 * exists to avoid silently mis-capturing a saved-report mutation as some
 * other table's op-log row.
 *
 * `reportId` is `saved_reports.id` — a LOCAL autoincrement surrogate created
 * by `SaveReport` via an Eloquent `create()`. For the common create-once-
 * then-edit lifecycle the pk is stable across subsequent edits on the origin
 * device, so per-(table, pk, field) LWW merge converges for that case.
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 */
final readonly class SavedReportMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     *                                             Empty for delete ops.
     */
    public function __construct(
        public int $reportId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
