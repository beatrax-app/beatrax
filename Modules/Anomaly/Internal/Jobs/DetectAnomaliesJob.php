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

    // $importRunId is nullable because it can genuinely be absent: unserialising
    // a payload queued before this job took a run leaves it unset, and the type
    // has to admit that or every reader of it is written against a lie.
    public function __construct(
        public readonly int $userId,
        public readonly ?int $importRunId,
    ) {}

    // Set only by unserialising a payload queued before this job took an
    // import run instead of a single row. PHP leaves $importRunId
    // uninitialised for those, and reading a typed property in that state is a
    // fatal Error -- raised from uniqueId(), which the queue calls OUTSIDE the
    // handler's try/catch, so nothing was ever recorded as failed and every
    // such job retried forever. The population most likely to hold thousands
    // of them is exactly the one upgrading to the fix that stops making them.
    public ?int $transactionId = null;

    public function uniqueId(): string
    {
        if (! isset($this->importRunId)) {
            return "{$this->userId}:tx{$this->transactionId}";
        }

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

        // An old payload names one row and knows nothing about a run. It is
        // evaluated as it was going to be: draining what is already queued
        // costs what it always would have, and discarding it would silently
        // skip anomaly detection on rows that are already in the ledger.
        if (! isset($this->importRunId)) {
            if ($this->transactionId !== null) {
                $evaluator->evaluate($this->transactionId, $user);
            }

            return;
        }

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
