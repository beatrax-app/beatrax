<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Services;

use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;

/**
 * Concrete implementation of the `DispatchesChainResolution` contract.
 * Wraps `ResolveChainLinksJob::dispatch($userId)` (the
 * `Dispatchable` trait's static helper that returns a
 * `Illuminate\Foundation\Bus\PendingDispatch` instance) so the
 * concrete job class stays inside `Modules\Chains\Internal\Jobs\`
 * (which `App\PhpStan\Rules\BoundaryRule` forbids other modules from
 * importing directly).
 *
 * The `Dispatchable::dispatch()` helper is the right entry point for
 * `ShouldBeUnique` jobs: the `PendingDispatch::shouldDispatch()` check
 * (Laravel framework v12.x line 209+) acquires the UniqueLock via
 * `$job->uniqueVia()` BEFORE the queue push. Routing through
 * `Bus::dispatch(new Job(...))` bypasses PendingDispatch entirely and
 * skips the lock — which would let a parallel dispatch from a second
 * tab race the first into Redis (regression of ARCHITECTURE.md L446).
 *
 * Bound as a singleton in `ChainsServiceProvider::register()`.
 *
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 */
final class BusChainResolutionDispatcher implements DispatchesChainResolution
{
    public function dispatchForUser(int $userId): void
    {
        ResolveChainLinksJob::dispatch($userId);
    }
}
