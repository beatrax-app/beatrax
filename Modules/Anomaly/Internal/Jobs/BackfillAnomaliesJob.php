<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Core\Public\Support\RowChunk;
use stdClass;

/**
 * @link ../../../../.docs/conventions/a-check-another-writer-can-invalidate.md#a-claim-is-not-a-completion
 */
final class BackfillAnomaliesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    private const int CHUNK = RowChunk::DEFAULT_SIZE;

    // How long a claim stands without the walk holding it saying anything.
    // Above the 300s worker timeout in config/nativephp.php so a live walk is
    // never taken off its own claim, and refreshed every chunk, so only a walk
    // that stopped moving can let it lapse.
    private const int CLAIM_LEASE_SECONDS = 600;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return self::CLAIM_LEASE_SECONDS;
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

        // The completion, which is a different fact from the claim below: a
        // walk that was claimed and then killed leaves this null, because the
        // rest of that history is still owed.
        if ($user->anomaly_backfilled_at !== null) {
            return;
        }

        $connection = $db->connection();

        if (! $this->claim($connection, $clock)) {
            return;
        }

        $this->walk($connection, $evaluator, $clock, $user);
        $this->complete($connection, $clock);
    }

    // ShouldBeUniqueUntilProcessing releases its lock the instant handle()
    // begins, so two close dispatches could otherwise both walk full history.
    // The row is the mutex: one runner holds it, and the walk it guards can be
    // resumed because finishing is recorded separately, in complete().
    private function claim(Connection $connection, Clock $clock): bool
    {
        $now = $clock->now()->toDateTimeString();

        $opened = $connection->table('anomaly_backfill_state')->insertOrIgnore([
            'user_id' => $this->userId,
            'claimed_at' => $now,
            'claimed_by' => $this->runner(),
            'started_at' => $now,
            'updated_at' => $now,
        ]);

        if ($opened === 1) {
            return true;
        }

        $lapsedBefore = $clock->now()->subSeconds(self::CLAIM_LEASE_SECONDS)->toDateTimeString();

        $taken = $connection->table('anomaly_backfill_state')
            ->where('user_id', $this->userId)
            ->whereNull('completed_at')
            ->where(function (Builder $held) use ($lapsedBefore): void {
                $held->where('claimed_by', $this->runner())
                    ->orWhere('claimed_at', '<', $lapsedBefore);
            })
            ->update([
                'claimed_at' => $now,
                'claimed_by' => $this->runner(),
                'updated_at' => $now,
            ]);

        return $taken === 1;
    }

    // A queued attempt carries a uuid that survives its own retries, so a
    // retry meets its own claim and reads it as its own rather than as a
    // second worker's. A run with no queue under it has no rival to be
    // confused with, and the empty string is its whole identity.
    private function runner(): string
    {
        return $this->job?->uuid() ?? '';
    }

    private function walk(Connection $connection, AnomalyEvaluator $evaluator, Clock $clock, User $user): void
    {
        $connection->table('transactions')
            ->where('user_id', $this->userId)
            ->where('id', '>', $this->cursor($connection))
            ->select('id')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $rows) use ($connection, $evaluator, $clock, $user): void {
                $reached = 0;

                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $transactionId = is_numeric($row->id) ? (int) $row->id : 0;
                    if ($transactionId <= 0) {
                        continue;
                    }

                    $evaluator->evaluate($transactionId, $user);
                    $reached = max($reached, $transactionId);
                }

                if ($reached > 0) {
                    $this->checkpoint($connection, $clock, $reached);
                }
            });
    }

    private function cursor(Connection $connection): int
    {
        /** @var mixed $reached */
        $reached = $connection->table('anomaly_backfill_state')
            ->where('user_id', $this->userId)
            ->value('cursor_transaction_id');

        return is_numeric($reached) ? (int) $reached : 0;
    }

    // Per chunk rather than per walk: this is the history a kill can no longer
    // cost, and the same statement refreshes the lease, so a walk still moving
    // keeps the claim it is moving under.
    private function checkpoint(Connection $connection, Clock $clock, int $reached): void
    {
        $now = $clock->now()->toDateTimeString();

        $connection->table('anomaly_backfill_state')
            ->where('user_id', $this->userId)
            ->update([
                'cursor_transaction_id' => $reached,
                'claimed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    // Both halves of "the walk finished" in one transaction: the settings
    // screen reads the user column to decide whether to dispatch at all, and
    // a completion recorded in only one of the two is a walk that either runs
    // again from the top or never runs again at all.
    private function complete(Connection $connection, Clock $clock): void
    {
        $now = $clock->now()->toDateTimeString();

        $connection->transaction(function () use ($connection, $now): void {
            $connection->table('anomaly_backfill_state')
                ->where('user_id', $this->userId)
                ->update(['completed_at' => $now, 'updated_at' => $now]);

            $connection->table('users')
                ->where('id', $this->userId)
                ->update(['anomaly_backfilled_at' => $now]);
        });
    }
}
