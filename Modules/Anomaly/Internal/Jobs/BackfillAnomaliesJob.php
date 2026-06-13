<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use stdClass;

/**
 * Full-history anomaly backfill, dispatched ONCE on first activation
 * (e.g. when the user first enables anomaly detection in settings, Plan
 * 05) — never inline (D-13/D-14, T-09-15).
 *
 * Concurrency + idempotency contract:
 *  - ShouldBeUniqueUntilProcessing keyed on uniqueId() = (string) userId
 *    collapses any duplicate activation dispatch into a single queued run
 *    per user. The lock releases the moment a worker begins handle().
 *  - The users.anomaly_backfilled_at guard makes the WHOLE job a no-op on
 *    a second dispatch: a non-null timestamp short-circuits before any
 *    iteration, so the job never re-walks years of history.
 *  - Per-row, the shared AnomalyEvaluator::evaluate() path is idempotent
 *    on UNIQUE(transaction_id): a crash + retry skips already-alerted
 *    rows, making the backfill resumable.
 *
 * Memory contract: the user's full transaction history is enumerated via
 * ->lazyById(self::CHUNK) so a multi-year history never loads into memory
 * at once (RESEARCH §Backfill Performance / Pitfall 4 — the evaluator's
 * floor + strictness keep day-one alert volume sane).
 *
 * Cross-user discipline (T-09-16): the iteration query carries an
 * explicit where('user_id', ...) — the BelongsToUser global scope does
 * not fire under queue/console, and the backfill must never read another
 * user's transactions. Backfilled alerts land in the normal Open queue
 * with no special muting (D-14).
 */
final class BackfillAnomaliesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Chunk size for the lazyById history walk (RESEARCH §Backfill). */
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

        // D-13: first-activation guard. A non-null timestamp means the
        // full history was already walked — the whole job no-ops.
        if ($user->anomaly_backfilled_at !== null) {
            return;
        }

        $db->connection()->table('transactions')
            ->where('user_id', $this->userId)
            ->select('id')
            ->lazyById(self::CHUNK)
            ->each(function (stdClass $row) use ($evaluator, $user): void {
                $transactionId = is_numeric($row->id) ? (int) $row->id : 0;
                if ($transactionId <= 0) {
                    return;
                }
                // Shared evaluator path — same detection logic as the
                // reactive job + safety-net sweep. UNIQUE(transaction_id)
                // makes a re-run skip already-alerted rows (resumable).
                $evaluator->evaluate($transactionId, $user);
            });

        // D-13: mark the backfill complete so a second dispatch no-ops.
        $db->connection()->table('users')
            ->where('id', $this->userId)
            ->update(['anomaly_backfilled_at' => $clock->now()->toDateTimeString()]);
    }
}
