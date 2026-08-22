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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Core\Public\Support\RowChunk;
use stdClass;

final class SafetyNetAnomalySweepJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    private const RECENT_WINDOW_DAYS = 30;

    private const int CHUNK = RowChunk::DEFAULT_SIZE;

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
                $evaluator->evaluate($transactionId, $user);
            });
    }
}
