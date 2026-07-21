<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Dto\SubscriptionDriftRow;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
final class SubscriptionDriftWatchQuery
{
    // Covers any realistic subscription lifetime (50 years monthly / 11
    // years weekly) — see the class @link for why the full history is read.
    private const FULL_HISTORY_POINTS = 600;

    public function __construct(
        private readonly RecurringSeriesQuery $series,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return list<SubscriptionDriftRow>
     */
    public function forUser(User $user): array
    {
        $openAlertSeriesIds = $this->openAlertSeriesIds($user);

        $rows = [];
        foreach ($this->series->allApprovedForUser($user) as $dto) {
            if ($dto->direction !== 'expense') {
                continue;
            }

            $trend = $this->series->amountTrendForSeries($dto->seriesId, $user, self::FULL_HISTORY_POINTS);
            $points = $trend->points;
            if (count($points) < 2) {
                continue;
            }

            // Expense amounts are negative; compare the ABSOLUTE price so a
            // more-expensive charge reads as a positive (upward) drift.
            $baseline = abs($points[0]['amount_minor']);
            $latest = abs($points[count($points) - 1]['amount_minor']);
            $delta = $latest - $baseline;

            $rows[] = new SubscriptionDriftRow(
                seriesId: $dto->seriesId,
                name: $dto->displayName(),
                baselineMinor: $baseline,
                latestMinor: $latest,
                deltaMinor: $delta,
                deltaPercent: $baseline > 0 ? $delta / $baseline * 100 : 0.0,
                monthlyEquivalentMinor: abs($dto->monthlyEquivalent->toMinor()),
                currency: $trend->currency,
                points: array_map(
                    static fn (array $point): array => ['date' => $point['date'], 'amount_minor' => abs($point['amount_minor'])],
                    $points,
                ),
                hasOpenAlert: isset($openAlertSeriesIds[$dto->seriesId]),
            );
        }

        usort($rows, static fn (SubscriptionDriftRow $a, SubscriptionDriftRow $b): int => $b->deltaMinor <=> $a->deltaMinor);

        return $rows;
    }

    /**
     * @return array<int, true> series ids with an unresolved drift alert
     */
    private function openAlertSeriesIds(User $user): array
    {
        $ids = [];
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('state', 'open')
            ->get(['recurring_series_id']);

        foreach ($rows as $row) {
            if (is_numeric($row->recurring_series_id)) {
                $ids[(int) $row->recurring_series_id] = true;
            }
        }

        return $ids;
    }
}
