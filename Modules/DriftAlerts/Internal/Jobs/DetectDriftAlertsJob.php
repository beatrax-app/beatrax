<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Support\LockStore;
use Modules\DriftAlerts\Internal\DriftEvaluator;

/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
final class DetectDriftAlertsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function __construct(
        public readonly int $userId,
        public readonly int $recurringSeriesId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->recurringSeriesId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(DriftEvaluator $evaluator): void
    {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        $evaluator->evaluateForSeries($this->recurringSeriesId, $user);
    }
}
