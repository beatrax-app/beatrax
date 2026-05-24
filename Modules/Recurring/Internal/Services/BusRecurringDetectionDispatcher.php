<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Services;

use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

/**
 * Concrete implementation of `DispatchesRecurringDetection`. Wraps
 * `DetectRecurringSeriesJob::dispatch($userId)` so the concrete job
 * class stays inside `Modules\Recurring\Internal\Jobs\` (which
 * `App\PhpStan\Rules\BoundaryRule` forbids other modules from
 * importing directly).
 *
 * The `Dispatchable::dispatch()` helper is the right entry point for
 * `ShouldBeUnique` jobs: `PendingDispatch::shouldDispatch()` acquires
 * the UniqueLock via `$job->uniqueVia()` BEFORE the queue push, so a
 * parallel dispatch from a second tab cannot race the first into
 * Redis. Bound as a singleton in `RecurringServiceProvider::register()`.
 *
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 */
final class BusRecurringDetectionDispatcher implements DispatchesRecurringDetection
{
    public function dispatchForUser(int $userId): void
    {
        DetectRecurringSeriesJob::dispatch($userId);
    }
}
