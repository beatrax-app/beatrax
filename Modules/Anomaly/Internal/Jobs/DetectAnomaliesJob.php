<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LockStore;

/**
 * Per-(user, transaction) anomaly evaluation. Dispatched by
 * EvaluateAnomaliesOnTransactionImport after the Import module fires
 * TransactionImported — detection runs HERE on the queue, never inline
 * in the synchronous import transaction (D-12 / T-09-14).
 *
 * Concurrency contract (cloned from DetectDriftAlertsJob, re-keyed to the
 * per-transaction shape):
 *  - ShouldBeUniqueUntilProcessing keyed on uniqueId() =
 *    "{userId}:{transactionId}" collapses any concurrent
 *    (reactive-import + safety-net-sweep + backfill) trigger trio into a
 *    single queued job per (user, transaction). The lock releases the
 *    moment a worker begins handle(). A re-import of the same row
 *    collapses harmlessly here AND at the UNIQUE(transaction_id) seam in
 *    AnomalyEvaluator.
 *  - tries = 3 + backoff = [60, 300, 900] keeps a transient queue or DB
 *    hiccup from final-failing the evaluation without two retries.
 *
 * Queue-uniqueness lock resolution is delegated to the shared
 * Modules\Core\Public\Support\LockStore helper: uniqueVia() returns
 * LockStore::forUniqueJobs(), which resolves the cache store named by
 * config('cache.locks_store').
 *
 * handle() resolves the User via firstOrFail (mirrors DetectDriftAlertsJob)
 * and hands off to the single AnomalyEvaluator::evaluate() entry point
 * shared by the backfill + safety-net sweep — no duplicate detection logic.
 */
final class DetectAnomaliesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
        public readonly int $transactionId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->transactionId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(AnomalyEvaluator $evaluator): void
    {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        $evaluator->evaluate($this->transactionId, $user);
    }
}
