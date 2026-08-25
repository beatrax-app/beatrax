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
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Support\LockStore;
use stdClass;

final class DetectAnomaliesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use CoercesScalars;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function __construct(
        public readonly int $userId,
        public readonly int $importRunId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->importRunId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    // One job per import run, not per row. The key was per-transaction, so it
    // deduplicated nothing across an import: 3,300 rows meant 3,300 jobs and
    // about two and a half hours of queue work on a phone, for one file.
    public function handle(AnomalyEvaluator $evaluator, DatabaseManager $db): void
    {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        $rows = $db->connection()->table('transactions')
            ->where('user_id', $this->userId)
            ->where('import_run_id', $this->importRunId)
            ->orderBy('id')
            ->get(['id']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $evaluator->evaluate(self::toInt($row->id), $user);
        }
    }
}
