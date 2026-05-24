<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Contracts;

/**
 * Public contract for "kick off the recurring-series detection sweep
 * for one user".
 *
 * `Modules\Import\Public\Actions\ConfirmImport` injects this contract
 * and calls `dispatchForUser($user->id)` after the import's outer DB
 * transaction commits, mirroring the chain-resolver post-commit
 * dispatch. The implementation pushes
 * `Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob` onto the
 * queue — keeping the concrete job class on the Internal surface
 * preserves the cross-module boundary that
 * `App\PhpStan\Rules\BoundaryRule` enforces.
 *
 * The contract is intentionally minimal — userId is the only thing
 * the detector needs (every internal query is user-scoped). The job's
 * own `ShouldBeUniqueUntilProcessing` collapses duplicate dispatches
 * for the same user into a single queued instance, so the contract
 * caller may dispatch freely without rate-limiting.
 */
interface DispatchesRecurringDetection
{
    /**
     * Push a DetectRecurringSeriesJob onto the queue for the given user.
     *
     * Idempotent at the contract layer: `ShouldBeUniqueUntilProcessing`
     * inside the job class collapses duplicate dispatches for the same
     * user into a single queued instance (the lock is released the
     * moment a worker begins processing the job).
     */
    public function dispatchForUser(int $userId): void;
}
