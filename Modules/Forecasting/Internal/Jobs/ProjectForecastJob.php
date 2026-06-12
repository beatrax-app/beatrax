<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LockStore;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;

/**
 * Async forecast projection job.
 *
 * Concurrency contract:
 *  - ShouldBeUniqueUntilProcessing keyed on
 *    `uniqueId() = "{userId}:{scenarioKey}:{horizonDays}"` where
 *    `scenarioKey` is the literal string `'baseline'` for a null
 *    scenarioId and the stringified scenario id otherwise. The
 *    `'baseline'` sentinel disambiguates the null case from a literal
 *    scenario id of 0 — the lock key is plain string equality, so
 *    `(5, null, 30)` and `(5, 0, 30)` must produce DIFFERENT keys
 *    (`'5:baseline:30'` vs `'5:0:30'`) to avoid silent collisions.
 *  - tries = 3 + backoff = [60, 300, 900] (mirrors DetectDriftAlertsJob)
 *    so a transient queue / DB hiccup retries without final-failing
 *    the run.
 *
 * Constructor-time validation: `horizonDays` must be one of the five
 * supported windows (30, 60, 90, 180, 365). Any other value raises
 * `InvalidArgumentException` so a tampered Livewire payload or a
 * misuse from a later wave's feature flag cannot reach the queue
 * worker. Phase 6 extends the set from 3 to 5 horizons to support
 * the 12-month calendar reach (D-14).
 *
 * Queue-uniqueness lock resolution is delegated to the shared
 * `Modules\Core\Public\Support\LockStore` helper: `uniqueVia()`
 * returns `LockStore::forUniqueJobs()`, which resolves the cache store
 * named by `config('cache.locks_store')`.
 *
 * `handle()` resolves the User via `firstOrFail` and hands off to
 * `ProjectionPipeline` which owns the math, the per-account fold, the
 * `forecast_runs.result_json` write, and the state-machine transitions.
 */
final class ProjectForecastJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var list<int> */
    public const HORIZON_DAYS = [30, 60, 90, 180, 365];

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
        public readonly ?int $scenarioId,
        public readonly int $horizonDays,
    ) {
        if (! in_array($this->horizonDays, self::HORIZON_DAYS, true)) {
            throw new InvalidArgumentException(sprintf(
                'ProjectForecastJob: horizonDays must be one of [30, 60, 90, 180, 365]; got %d.',
                $this->horizonDays,
            ));
        }
    }

    public function uniqueId(): string
    {
        $scenarioKey = $this->scenarioId !== null
            ? (string) $this->scenarioId
            : 'baseline';

        return "{$this->userId}:{$scenarioKey}:{$this->horizonDays}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(ProjectionPipeline $pipeline): void
    {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        $pipeline->project($user, $this->scenarioId, $this->horizonDays);
    }
}
