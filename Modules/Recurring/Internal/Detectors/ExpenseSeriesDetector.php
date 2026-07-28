<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Events\RecurringSeriesDetected;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use stdClass;

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final class ExpenseSeriesDetector implements SeriesDetector
{
    use CoercesScalars;

    private const DEFAULT_WINDOW_MONTHS = 2;

    private const DEFAULT_VARIANCE_TOLERANCE_PERCENT = 25;

    private const MIN_OCCURRENCES = 2;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CadenceInferrer $cadenceInferrer,
        private readonly ClusterKeyComposer $clusterKeyComposer,
        private readonly Dispatcher $events,
        private readonly OccurrenceWriter $occurrences,
        private readonly SeriesRefresher $refresher,
    ) {}

    public function detectForUser(User $user): void
    {
        $windowMonths = $user->recurring_detection_window_months;
        if ($windowMonths <= 0) {
            $windowMonths = self::DEFAULT_WINDOW_MONTHS;
        }
        $since = $this->clock->now()->subMonths($windowMonths)->toDateString();

        $rows = $this->db->connection()->table('transactions')
            ->select([
                'id',
                'posted_at',
                'booked_at',
                'amount_minor',
                'currency',
                'settled_amount_minor',
                'settled_currency',
                'fx_rate_used',
                'counterparty_normalized',
                'counterparty_iban',
            ])
            ->where('user_id', $user->id)
            ->whereIn('type', ['expense', 'fee', 'refund'])
            ->where('posted_at', '>=', $since)
            ->orderBy('posted_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $counterparty = self::toString($row->counterparty_normalized);
            $currency = self::toString($row->currency);
            if ($counterparty === '' || $currency === '') {
                continue;
            }
            $key = $counterparty.'|'.$currency;
            $groups[$key] = $groups[$key] ?? [
                'counterparty_normalized' => $counterparty,
                'currency' => $currency,
                'rows' => [],
            ];
            $groups[$key]['rows'][] = $row;
        }

        foreach ($groups as $group) {
            $this->processCluster($user, $group['counterparty_normalized'], $group['currency'], $group['rows']);
        }
    }

    /**
     * @param  list<stdClass>  $rows
     */
    private function processCluster(User $user, string $counterparty, string $currency, array $rows): void
    {
        $qualified = $this->qualifyCluster($user, $counterparty, $currency, $rows);
        if ($qualified === null) {
            return;
        }
        [$filtered, $cadenceResult] = $qualified;

        $clusterKey = $this->clusterKeyComposer->compose(
            'expense',
            $counterparty,
            $currency,
            $cadenceResult['cadence'],
        );

        // cluster_key already encodes cadence + currency, so it's the
        // natural dedupe seam; a cadence flip on an approved row resolves
        // here since the cluster_key tokens carry the new cadence class.
        /** @var RecurringSeries|null $existingBySameCluster */
        $existingBySameCluster = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', 'expense')
            ->where('cluster_key', $clusterKey)
            ->where('latest_currency', $currency)
            ->first();

        // Also matches on the persisted cluster_counterparty_key so a
        // cadence-flip is detected on the existing row rather than
        // inserting a new one, symmetric with the income detector and
        // unaffected if detected_name is ever decorated separately.
        /** @var RecurringSeries|null $existingByCounterparty */
        $existingByCounterparty = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', 'expense')
            ->where('cluster_counterparty_key', $counterparty)
            ->where('latest_currency', $currency)
            ->first();

        $existing = $existingBySameCluster ?? $existingByCounterparty;

        $latestRow = $filtered[count($filtered) - 1];
        $latestAmount = self::toInt($latestRow->amount_minor);
        $nextExpectedAt = $cadenceResult['next_expected_at'];

        $detected = DetectedSeries::fromCadence($clusterKey, $cadenceResult, $latestAmount, $currency, $filtered);

        if ($existing === null) {
            $this->insertNewSeries($user, $counterparty, $detected);

            return;
        }

        // Both states mean leave this row alone. Rejection covers the whole
        // (counterparty, currency) pair across every cadence variant; a
        // snoozed row would surface a different amount than the one the user
        // paused on, and the next sweep's expiry pass unpauses it first.
        if (in_array($existing->state, ['rejected', 'snoozed'], true)) {
            return;
        }

        $this->refresher->refresh($existing, $counterparty, $detected, $user, 'expense');
    }

    // A cluster qualifies once it still has enough occurrences after the
    // variance filter and the intervals resolve to a real cadence. Null
    // means one of those three tests failed and there is nothing to record.
    /**
     * @param  list<stdClass>  $rows
     * @return array{0: list<stdClass>, 1: array{cadence: 'weekly'|'monthly'|'quarterly'|'yearly'|'irregular', median_interval_days: float, next_expected_at: ?CarbonImmutable, confidence_low: bool, missed_count: int}}|null
     */
    private function qualifyCluster(User $user, string $counterparty, string $currency, array $rows): ?array
    {
        if (count($rows) < self::MIN_OCCURRENCES) {
            return null;
        }

        $tolerance = $this->existingToleranceFor($user, $counterparty, $currency)
            ?? self::DEFAULT_VARIANCE_TOLERANCE_PERCENT;
        $filtered = self::applyVarianceFilter($rows, $tolerance);
        if (count($filtered) < self::MIN_OCCURRENCES) {
            return null;
        }

        $timestamps = [];
        foreach ($filtered as $row) {
            $timestamps[] = CarbonImmutable::parse(self::toString($row->posted_at));
        }
        $cadenceResult = $this->cadenceInferrer->infer($timestamps);

        return $cadenceResult['cadence'] === 'irregular' ? null : [$filtered, $cadenceResult];
    }

    /**
     * @return int|null the variance tolerance percent stored on an existing series for this
     *                  (user, counterparty, currency), or null when none exists yet — honours user-edited
     *                  tolerance values on the next sweep so a widened tolerance does not fragment the cluster
     */
    private function existingToleranceFor(User $user, string $counterparty, string $currency): ?int
    {
        $row = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('direction', 'expense')
            ->where('cluster_counterparty_key', $counterparty)
            ->where('latest_currency', $currency)
            ->whereIn('state', ['pending', 'approved', 'cadence_changed', 'snoozed'])
            ->first(['variance_tolerance_percent']);

        if ($row === null) {
            return null;
        }

        $value = $row->variance_tolerance_percent ?? null;
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<stdClass>
     */
    private static function applyVarianceFilter(array $rows, int $tolerancePercent): array
    {
        $absolutes = [];
        foreach ($rows as $row) {
            $absolutes[] = abs(self::toInt($row->amount_minor));
        }
        sort($absolutes);
        $count = count($absolutes);
        $mid = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $absolutes[$mid]
            : ($absolutes[$mid - 1] + $absolutes[$mid]) / 2;
        if ($median <= 0) {
            return $rows;
        }

        $lower = $median * (100 - $tolerancePercent) / 100;
        $upper = $median * (100 + $tolerancePercent) / 100;

        $kept = [];
        foreach ($rows as $row) {
            $abs = abs(self::toInt($row->amount_minor));
            if ($abs >= $lower && $abs <= $upper) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    private function insertNewSeries(User $user, string $counterparty, DetectedSeries $detected): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $newId = $connection->table('recurring_series')->insertGetId([
            'user_id' => $user->id,
            'direction' => 'expense',
            'detected_name' => $counterparty,
            'state' => 'pending',
            'cadence' => $detected->cadence,
            'latest_amount_minor' => $detected->latestAmountMinor,
            'latest_currency' => $detected->currency,
            'monthly_equivalent_minor' => $detected->monthlyEquivalentMinor,
            'variance_tolerance_percent' => self::DEFAULT_VARIANCE_TOLERANCE_PERCENT,
            'next_expected_at' => $detected->nextExpectedAt?->toDateString(),
            'next_expected_confidence_low' => $detected->confidenceLow,
            'cluster_key' => $detected->clusterKey,
            'cluster_counterparty_key' => $counterparty,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->occurrences->write($user->id, $newId, $detected->rows, $detected->currency);

        $this->events->dispatch(new RecurringSeriesDetected(
            seriesId: $newId,
            userId: $user->id,
            direction: 'expense',
            detectedName: $counterparty,
            cadence: $detected->cadence,
        ));

        $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
            userId: $user->id,
            recurringSeriesId: $newId,
            direction: 'expense',
            cadence: $detected->cadence,
            latestAmountMinor: $detected->latestAmountMinor,
            latestCurrency: $detected->currency,
        ));
    }
}
