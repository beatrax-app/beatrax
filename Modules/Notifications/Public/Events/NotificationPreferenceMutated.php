<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Events;

/**
 * Dispatched for every mutation to a `notification_preferences` row (D-34).
 *
 * Mirrors `Modules\Sync\Public\Events\SavedReportMutated`'s shape.
 * `SyncCaptureListener` (wired in plan 18-04) hard-codes the target table
 * name per event type, so a dedicated event exists here rather than
 * reusing a generic mutation event — avoids silently mis-capturing a
 * preference change as some other table's op-log row.
 *
 * `preferenceId` is `notification_preferences.id` — a LOCAL autoincrement
 * surrogate (D-34's migration note: this table is not independently
 * generated on two devices, so the usual sha256-PK convergence argument
 * does not apply here).
 *
 * Dispatched only AFTER the write has committed (D-28 emit-after-commit /
 * WR-06 contract) — never from inside an open transaction.
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 */
final readonly class NotificationPreferenceMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     */
    public function __construct(
        public int $preferenceId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
