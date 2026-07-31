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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Support\LockStore;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final class ProjectForecastJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    /** @var list<int> */
    public const HORIZON_DAYS = [30, 60, 90, 180, 365];

    public function __construct(
        public readonly int $userId,
        public readonly ?int $scenarioId,
        public readonly int $horizonDays,
    ) {
        // Rejects a tampered Livewire payload or any other caller-supplied
        // horizon so it never reaches the queue worker.
        if (! in_array($this->horizonDays, self::HORIZON_DAYS, true)) {
            throw new InvalidArgumentException(sprintf(
                'ProjectForecastJob: horizonDays must be one of [30, 60, 90, 180, 365]; got %d.',
                $this->horizonDays,
            ));
        }
    }

    public function uniqueId(): string
    {
        // The 'baseline' sentinel disambiguates a null scenarioId from a
        // literal scenario id of 0 — the lock key is plain string equality,
        // so (5, null, 30) and (5, 0, 30) must produce DIFFERENT keys
        // ('5:baseline:30' vs '5:0:30') to avoid silent collisions.
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
