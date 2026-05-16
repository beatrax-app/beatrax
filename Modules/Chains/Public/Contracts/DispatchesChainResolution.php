<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Contracts;

/**
 * Public contract for "kick off the Chains resolver for one user".
 *
 * `Modules\Import\Public\Actions\ConfirmImport` injects this contract
 * and calls `dispatchForUser($user->id)` after the import's outer DB
 * transaction commits (D-103 / RESEARCH Pitfall 3). The implementation
 * pushes `Modules\Chains\Internal\Jobs\ResolveChainLinksJob` onto the
 * Redis queue — keeping the concrete job class on the Internal surface
 * preserves the cross-module boundary that
 * `App\PhpStan\Rules\BoundaryRule` enforces.
 *
 * The contract is intentionally minimal — userId is the only thing
 * the resolver needs (every internal query is user-scoped per
 * FND-03). Future kinds (e.g. per-transaction re-scan) can extend
 * with separate methods rather than overloading dispatch arguments.
 */
interface DispatchesChainResolution
{
    /**
     * Push a ResolveChainLinksJob onto the queue for the given user.
     *
     * Idempotent at the contract layer: `ShouldBeUniqueUntilProcessing`
     * inside the job class collapses duplicate dispatches for the same
     * user into a single queued instance (the lock is released the
     * moment a worker begins processing the job). The contract caller
     * may dispatch freely without rate-limiting.
     */
    public function dispatchForUser(int $userId): void;
}
