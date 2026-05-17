<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Persists the per-series drift threshold override. The write is a
 * metric-style column update (never transitions state) so the
 * `noOtherRecurringSeriesStateMutator` invariant stays green. Lives on
 * the Recurring side of the boundary so the
 * `noRecurringSeriesWritesFromDriftAlerts` invariant stays green — the
 * DriftAlerts Livewire editor calls this action via method-parameter
 * DI; DriftAlerts retains zero direct writes to recurring_series.
 *
 * `$thresholdPercent === null` clears the override so the series falls
 * back to the user-global setting (which itself falls back to the hard
 * 5% default at the DriftEvaluator's effective-threshold resolution).
 *
 * Allowed values mirror the popover's six options (1 / 2 / 5 / 10 / 25
 * / 50) plus null. Any other integer raises InvalidArgumentException so
 * a tampered Livewire payload cannot smuggle an arbitrary percent onto
 * the row. Cross-user invocation raises NotFoundHttpException via the
 * WHERE-user_id predicate. Idempotent no-op when the new value equals
 * the current one.
 */
final class SetDriftThresholdForSeries
{
    /** @var list<int> */
    public const ALLOWED_THRESHOLD_PERCENTS = [1, 2, 5, 10, 25, 50];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $seriesId, User $user, ?int $thresholdPercent): void
    {
        if ($thresholdPercent !== null && ! in_array($thresholdPercent, self::ALLOWED_THRESHOLD_PERCENTS, true)) {
            throw new InvalidArgumentException(
                'Drift threshold must be null (use global default) or one of: 1, 2, 5, 10, 25, 50.',
            );
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
    }
}
