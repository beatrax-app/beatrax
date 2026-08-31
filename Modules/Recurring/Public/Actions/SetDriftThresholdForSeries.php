<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// null clears the override so the series falls back to the user-global
// setting (which itself falls back to the hard 5% default at the
// DriftEvaluator's effective-threshold resolution).

final readonly class SetDriftThresholdForSeries
{
    /** @var list<int> */
    public const array ALLOWED_THRESHOLD_PERCENTS = DriftThresholdOptions::PERCENTS;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(int $seriesId, User $user, ?int $thresholdPercent): void
    {
        if ($thresholdPercent !== null && ! in_array($thresholdPercent, self::ALLOWED_THRESHOLD_PERCENTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Drift threshold must be null (use global default) or one of: %s.',
                implode(', ', self::ALLOWED_THRESHOLD_PERCENTS),
            ));
        }

        $row = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first(['id', 'drift_threshold_percent']);

        if ($row === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        $rawCurrent = $row->drift_threshold_percent ?? null;
        $current = is_numeric($rawCurrent) ? (int) $rawCurrent : null;
        if ($current === $thresholdPercent) {
            return;
        }

        $now = $this->clock->now()->toDateTimeString();

        $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->update([
                'drift_threshold_percent' => $thresholdPercent,
                'updated_at' => $now,
            ]);

        $this->events->dispatch(new EntityMutated(
            table: 'recurring_series',
            pk: $seriesId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['drift_threshold_percent' => $thresholdPercent],
        ));
    }
}
