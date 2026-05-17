<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Public contract implemented by every concrete recurring-series
 * detector that the sweep job dispatches.
 *
 * Implementations register as container-tagged singletons in
 * `Modules\Recurring\Providers\RecurringServiceProvider::register()`,
 * tagged with `'recurring.detector'`. The sweep job receives the
 * detector set via iterable injection on `handle()` so adding a new
 * detector is a one-line provider tag rather than a job-class edit.
 *
 * Analytical-only invariant: implementations MUST NOT write the
 * `transactions` table. The `noTransactionWritesFromRecurring` arch
 * test enforces this at the static-analysis level; the Recurring
 * module owns its own tables and reads transactions read-only.
 *
 * The `noSynchronousDetectionInRequestLifecycle` arch test enforces a
 * second invariant: SeriesDetector implementations and this contract
 * itself must not be imported from `Modules\Recurring\Internal\Http`
 * or `Modules\Recurring\Resources`. Detector work runs on the queue,
 * never in the request lifecycle.
 */
interface SeriesDetector
{
    public function detectForUser(User $user): void;
}
