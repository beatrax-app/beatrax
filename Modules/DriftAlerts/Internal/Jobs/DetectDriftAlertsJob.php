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
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;

/**
 * Per-(user, series) drift evaluation. Dispatched by
 * EvaluateDriftOnMetricsRefreshed after each Recurring sweep refreshes
 * a series's metric columns.
 *
 * Concurrency contract:
 *  - ShouldBeUniqueUntilProcessing keyed on uniqueId() = "{userId}:{seriesId}"
 *    collapses any concurrent (scheduled-tick + on-demand-redetect)
 *    trigger pair into a single queued job per (user, series). The
 *    lock releases the moment a worker begins handle().
 *  - tries = 3 + backoff = [60, 300, 900] keeps a transient queue or
 *    DB hiccup from final-failing the evaluation without two retries.
 *
 * Single permitted facade exception: the Cache::driver('redis') call
 * inside uniqueVia(). Laravel resolves the lock store at queue-push
 * time before constructor DI completes — a constructor-injected
 * Repository is not an option. The BoundaryArchTest facade-ignore
 * carve-out names this FQN explicitly.
 *
 * handle() resolves the User via firstOrFail (mirrors
 * DetectRecurringSeriesJob) and hands off to DriftEvaluator which
 * owns all of the math + persistence + DriftAlertOpened dispatch.
 */
final class DetectDriftAlertsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        return Cache::driver('redis');
    }

    public function handle(DriftEvaluator $evaluator): void
    {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        $evaluator->evaluateForSeries($this->recurringSeriesId, $user);
    }
}
