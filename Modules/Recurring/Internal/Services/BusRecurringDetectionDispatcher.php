<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Services;

use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

/**
 * Concrete implementation of `DispatchesRecurringDetection`. Wraps
 * `DetectRecurringSeriesJob::dispatchSync($userId)` so the concrete
 * job class stays inside `Modules\Recurring\Internal\Jobs\` (which
 * `App\PhpStan\Rules\BoundaryRule` forbids other modules from
 * importing directly).
 *
 * Runs via `dispatchSync` (14.1-04, CRYPT-01, fold-in per
 * `14.1-AUDIT.md`'s Dispatch-Origin Table): the detectors this job
 * runs read/group encrypted `counterparty_iban` to build recurrence
 * baselines, and the decryption KEK is only reachable through the
 * live, unlocked Session on the calling request. Every call site of
 * THIS dispatcher (`ConfirmImport`, `FirstImportStep`) is an unlocked
 * HTTP/Livewire request — the ONE daemon/scheduler origin for
 * `DetectRecurringSeriesJob` is a separate direct
 * `DetectRecurringSeriesJob::dispatch()` call in `routes/console.php`
 * (the daily sweep), which this dispatcher does not front and which
 * stays queued, owned by plan 08's skip-with-warning guard.
 *
 * `dispatchSync` keeps the KEK in-process — never serialized onto the
 * `jobs` table — and, as a consequence, bypasses the queue layer
 * entirely: the `ShouldBeUniqueUntilProcessing` lock on
 * `DetectRecurringSeriesJob` is only enforced by
 * `PendingDispatch::shouldDispatch()`, which `dispatchSync` never
 * invokes. A same-user double-dispatch (e.g. an import-confirm
 * followed immediately by a `/recurring` "Detect now" click) now runs
 * detection twice in sequence instead of collapsing into one queued
 * pass; detection is idempotent/re-run-safe, so this is a
 * redundant-but-harmless cost, not a correctness regression. Bound as
 * a singleton in `RecurringServiceProvider::register()`.
 *
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 */
final class BusRecurringDetectionDispatcher implements DispatchesRecurringDetection
{
    public function dispatchForUser(int $userId): void
    {
        DetectRecurringSeriesJob::dispatchSync($userId);
    }
}
