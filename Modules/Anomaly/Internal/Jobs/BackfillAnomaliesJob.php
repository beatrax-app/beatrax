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
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final class BackfillAnomaliesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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

        // First-activation guard: a non-null timestamp means the full
        // history was already walked — the whole job no-ops.
        if ($user->anomaly_backfilled_at !== null) {
            return;
        }

        // Claim the backfill BEFORE the walk via a conditional update that
        // acts as an atomic mutex: ShouldBeUniqueUntilProcessing releases
        // its lock the instant handle() begins, so two close dispatches
        // could otherwise both read null and both walk full history.
        $claimed = $db->connection()->table('users')
            ->where('id', $this->userId)
            ->whereNull('anomaly_backfilled_at')
            ->update(['anomaly_backfilled_at' => $clock->now()->toDateTimeString()]);

        if ($claimed === 0) {
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
                $evaluator->evaluate($transactionId, $user);
            });
    }
}
