<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class EditRecurringSeriesVarianceTolerance
{
    /** @var list<int> */
    public const array ALLOWED_TOLERANCE_PERCENTS = [10, 25, 50];

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(int $seriesId, User $user, int $newTolerancePercent): void
    {
        if (! in_array($newTolerancePercent, self::ALLOWED_TOLERANCE_PERCENTS, true)) {
            throw new InvalidArgumentException('Variance tolerance must be one of: 10, 25, 50.');
        }

        /** @var RecurringSeries|null $series */
        $series = RecurringSeries::query()
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        if ($series->variance_tolerance_percent === $newTolerancePercent) {
            return;
        }

        $now = $this->clock->now()->toDateTimeString();

        $this->db->connection()->table('recurring_series')
            ->where('id', $series->id)
            ->where('user_id', $user->id)
            ->update([
                'variance_tolerance_percent' => $newTolerancePercent,
                'updated_at' => $now,
            ]);

        $this->events->dispatch(new EntityMutated(
            table: 'recurring_series',
            pk: $series->id,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['variance_tolerance_percent' => $newTolerancePercent],
        ));
    }
}
