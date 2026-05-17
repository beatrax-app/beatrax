<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Updates per-series variance tolerance. Metric-style write — never
 * transitions state — so `noOtherRecurringSeriesStateMutator` stays
 * green. Cross-user 404 via the WHERE-user_id predicate. Idempotent
 * no-op when the new value equals the current one.
 *
 * Allowed values are whitelisted to `10 / 25 / 50` per the UI's fixed
 * dropdown options. Any other value raises `InvalidArgumentException`
 * so a tampered Livewire payload cannot smuggle an arbitrary percent
 * onto the row.
 */
final class EditRecurringSeriesVarianceTolerance
{
    /** @var list<int> */
    public const ALLOWED_TOLERANCE_PERCENTS = [10, 25, 50];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
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
    }
}
