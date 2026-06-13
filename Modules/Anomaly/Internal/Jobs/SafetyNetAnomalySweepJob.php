<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use stdClass;

/**
 * Per-user safety-net sweep: re-evaluates recently-imported transactions
 * that have NO anomaly_alerts row yet, catching any charge the reactive
 * EvaluateAnomaliesOnTransactionImport listener missed (a dropped queue
 * job, a worker crash mid-import). Dispatched hourly per user from
 * `routes/console.php` (anomaly.safety-net-sweep), one job per user.
 *
 * Concurrency contract:
 *  - ShouldBeUniqueUntilProcessing keyed on uniqueId() = (string) userId
 *    collapses any same-hour duplicate dispatch into a single queued
 *    sweep per user.
 *  - tries=3 + backoff=[60,300,900] tolerates a transient hiccup.
 *
 * Recency window: only transactions imported within RECENT_WINDOW_DAYS
 * (by created_at — the import timestamp) are candidates, so the sweep
 * stays cheap and does NOT re-walk history (that is the one-shot
 * BackfillAnomaliesJob's job). The candidate filter is a NOT EXISTS
 * against anomaly_alerts so already-alerted rows are never re-evaluated;
 * UNIQUE(transaction_id) is the final backstop making any race a no-op.
 *
 * Cross-user discipline (T-09-16): the candidate query carries an explicit
 * where('user_id', ...) — the BelongsToUser global scope does not fire
 * under queue/console.
 */
final class SafetyNetAnomalySweepJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Recent-import window (days) the sweep re-checks. */
    private const RECENT_WINDOW_DAYS = 30;

    /** Chunk size for the candidate walk. */
    private const CHUNK = 500;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(AnomalyEvaluator $evaluator, DatabaseManager $db, Clock $clock): void
    {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        $cutoff = $clock->now()->subDays(self::RECENT_WINDOW_DAYS)->toDateTimeString();
        $connection = $db->connection();

        $connection->table('transactions')
            ->where('transactions.user_id', $this->userId)
            ->where('transactions.created_at', '>=', $cutoff)
            ->whereNotExists(function (Builder $sub): void {
                $sub->select($sub->raw(1))
                    ->from('anomaly_alerts')
                    ->whereColumn('anomaly_alerts.transaction_id', 'transactions.id');
            })
            ->orderBy('transactions.id')
            ->select('transactions.id')
            ->lazyById(self::CHUNK, 'transactions.id', 'id')
            ->each(function (stdClass $row) use ($evaluator, $user): void {
                $transactionId = is_numeric($row->id) ? (int) $row->id : 0;
                if ($transactionId <= 0) {
                    return;
                }
                // Shared evaluator path — same detection logic as the
                // reactive job + backfill. UNIQUE(transaction_id) makes a
                // concurrent reactive insert collapse harmlessly.
                $evaluator->evaluate($transactionId, $user);
            });
    }
}
