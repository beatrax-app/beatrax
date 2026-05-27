<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Public\Support\LockStore;

/**
 * Per-user daily counterparty garbage-collector job. Prunes
 * `counterparties` rows that have become orphans — no transaction
 * within the last 365 days carries the row's FK AND no
 * `merchant_aliases` row anchors the row via
 * `friendly_name = counterparties.merchant_name`. The two-key check
 * is the load-bearing contract: a row preserved by a merchant alias
 * survives a quiet year, and a row preserved by recent activity
 * survives across alias renames.
 *
 * Concurrency contract:
 *  - ShouldBeUniqueUntilProcessing keyed on uniqueId() = "{userId}"
 *    collapses a same-user scheduled tick + on-demand sweep into one
 *    queued job. The lock releases the moment a worker begins
 *    handle(); a long-running GC pass therefore never blocks a
 *    follow-up tick once it has begun executing.
 *  - tries = 3 + backoff = [60, 300, 900] keeps a transient queue or
 *    DB hiccup from final-failing the prune without two retries.
 *  - uniqueVia() returns LockStore::forUniqueJobs() so the
 *    queue-uniqueness lock travels through the shared cache store
 *    every other ShouldBeUnique* job in the project uses.
 *
 * Pruning safety:
 *  - The prune runs in a single DB transaction so a half-applied
 *    purge can never land. Before the DELETE on counterparties, the
 *    transaction NULLs out `transactions.counterparty_id` for every
 *    row that points at a soon-to-be-pruned counterparty. Historical
 *    transactions retain their data — only the FK link is severed —
 *    which is exactly the per-Phase-17 schema decision in the
 *    `add_counterparty_id_to_transactions` migration (FK is from the
 *    user side, NOT cascaded from the counterparty side).
 *  - Cross-user posture: every WHERE clause carries an explicit
 *    `where('user_id', $this->userId)`. The job ONLY touches rows
 *    owned by the user that dispatched it; a per-user scheduled
 *    fan-out is the canonical entry point.
 *
 * Orphan predicate detail:
 *   counterparties.user_id = $userId
 *   AND no transactions row links via counterparty_id within the
 *       last 365 days (SQLite: `created_at >= datetime('now', '-365 days')`)
 *   AND counterparties.merchant_name IS NULL
 *       OR no merchant_aliases row exists with friendly_name =
 *          counterparties.merchant_name AND user_id = $userId.
 */
final class CounterpartyGarbageCollectorJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(DatabaseManager $db): void
    {
        $connection = $db->connection();

        $connection->transaction(function () use ($connection): void {
            // Step 1 — collect every counterparty id owned by this
            // user that satisfies the orphan predicate. Doing the
            // identification first keeps the DELETE bounded to a
            // known id list rather than a correlated subquery.
            /** @var list<int> $orphans */
            $orphans = $connection
                ->table('counterparties')
                ->where('counterparties.user_id', $this->userId)
                ->whereNotExists(function (Builder $query): void {
                    $query
                        ->select(new Expression('1'))
                        ->from('transactions')
                        ->whereColumn('transactions.counterparty_id', 'counterparties.id')
                        ->where('transactions.user_id', $this->userId)
                        // SQLite-portable date arithmetic. The
                        // 365-day window mirrors the long-history
                        // retention promise — a counterparty that
                        // has not been transacted with in a full
                        // year is a strong prune candidate.
                        ->whereRaw("transactions.created_at >= datetime('now', '-365 days')");
                })
                ->where(function (Builder $q): void {
                    $q
                        ->whereNull('counterparties.merchant_name')
                        ->orWhereNotExists(function (Builder $query): void {
                            $query
                                ->select(new Expression('1'))
                                ->from('merchant_aliases')
                                ->whereColumn(
                                    'merchant_aliases.friendly_name',
                                    'counterparties.merchant_name',
                                )
                                ->where('merchant_aliases.user_id', $this->userId);
                        });
                })
                ->pluck('counterparties.id')
                ->filter(static fn (mixed $id): bool => is_numeric($id))
                ->map(static fn (int|float|string $id): int => (int) $id)
                ->values()
                ->all();

            if ($orphans === []) {
                return;
            }

            // Step 2 — null out the FK on every transaction that
            // pointed at a soon-to-be-pruned counterparty. The
            // transactions.counterparty_id column is intentionally
            // free of an ON DELETE cascade so a prune can be done
            // safely without losing history; the NULL update keeps
            // referential integrity through the DELETE.
            $connection
                ->table('transactions')
                ->where('user_id', $this->userId)
                ->whereIn('counterparty_id', $orphans)
                ->update(['counterparty_id' => null]);

            // Step 3 — DELETE the orphans. Bounded by the explicit
            // user_id filter and the collected id list.
            $connection
                ->table('counterparties')
                ->where('user_id', $this->userId)
                ->whereIn('id', $orphans)
                ->delete();
        });
    }
}
