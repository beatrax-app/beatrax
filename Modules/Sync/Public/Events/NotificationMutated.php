<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * Dispatched for every user-driven mutation to a `notifications` row
 * (Req 11/12, D-05/D-09).
 *
 * Mirrors `SavedReportMutated`'s contract but carries a STRING id.
 * `$notificationId` is the D-05 deterministic sha256 hex-digest PK derived
 * by `DeterministicKeyDeriver` from `(user_id, trigger_type, subject_key,
 * occurrence)` — it flows straight through `OpLogWriter::writeSet(pk:)`
 * unchanged because `OpLogEntry::$pk` is already typed `int|string`. This is
 * precisely why D-05 needs zero engine change, unlike an autoincrement
 * surrogate (whose weak spot `SavedReportMutated`'s own docblock flags):
 * two devices independently deriving the same tuple compute the SAME pk and
 * converge on one row with no coordination.
 *
 * `SyncCaptureListener`'s handlers hard-code the target table name per event
 * type, so this dedicated event exists to avoid silently mis-capturing a
 * notification mutation as some other table's op-log row.
 *
 * Only `read_at` and `dismissed_at` are ever synced fields for 'edit'
 * mutations — `state` is deliberately excluded (18-01 <planner_decisions>;
 * `MergeRulesRegistry`'s `notifications` block has no `state` entry).
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 */
final readonly class NotificationMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     */
    public function __construct(
        public string $notificationId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
