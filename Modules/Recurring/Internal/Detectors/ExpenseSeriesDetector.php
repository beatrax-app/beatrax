<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesDetected;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use stdClass;

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final class ExpenseSeriesDetector implements SeriesDetector
{
    private const DEFAULT_WINDOW_MONTHS = 2;

    private const DEFAULT_VARIANCE_TOLERANCE_PERCENT = 25;

    private const MIN_OCCURRENCES = 2;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CadenceInferrer $cadenceInferrer,
        private readonly ClusterKeyComposer $clusterKeyComposer,
        private readonly RecurringSeriesStateMachine $stateMachine,
        private readonly Dispatcher $events,
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
            $counterparty = self::toStringNullable($row->counterparty_normalized);
            $currency = self::toStringNullable($row->currency);
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
        if (count($rows) < self::MIN_OCCURRENCES) {
            return;
        }

        $tolerance = $this->existingToleranceFor($user, $counterparty, $currency)
            ?? self::DEFAULT_VARIANCE_TOLERANCE_PERCENT;
        $filtered = self::applyVarianceFilter($rows, $tolerance);
        if (count($filtered) < self::MIN_OCCURRENCES) {
            return;
        }

        $timestamps = [];
        foreach ($filtered as $row) {
            $timestamps[] = CarbonImmutable::parse(self::toStringNullable($row->posted_at));
        }
        $cadenceResult = $this->cadenceInferrer->infer($timestamps);
        if ($cadenceResult['cadence'] === 'irregular') {
            return;
        }

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
        $monthlyEquivalent = self::monthlyEquivalent($latestAmount, $cadenceResult['cadence']);
        $nextExpectedAt = $cadenceResult['next_expected_at'];

        if ($existing === null) {
            $this->insertNewSeries(
                $user,
                $counterparty,
                $currency,
                $clusterKey,
                $cadenceResult['cadence'],
                $latestAmount,
                $monthlyEquivalent,
                $nextExpectedAt,
                $cadenceResult['confidence_low'],
                $filtered,
            );

            return;
        }

        if ($existing->state === 'rejected') {
            // Rejection covers the whole (counterparty, currency) pair
            // across every cadence variant, so a freshly-clustering
            // quarterly pattern for a merchant rejected at a monthly
            // cadence is intentionally suppressed too.
            return;
        }

        if ($existing->state === 'snoozed') {
            // Refreshing metrics in the background would surface a
            // different amount than the one the user paused on; the
            // next sweep's snooze-expiry pass flips snoozed -> pending
            // first, and normal refresh resumes after that.
            return;
        }

        $this->refreshExistingSeries(
            $existing,
            $clusterKey,
            $counterparty,
            $cadenceResult['cadence'],
            $latestAmount,
            $currency,
            $monthlyEquivalent,
            $nextExpectedAt,
            $cadenceResult['confidence_low'],
            $filtered,
            $user,
        );
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

    /**
     * @param  list<stdClass>  $rows
     */
    private function insertNewSeries(
        User $user,
        string $counterparty,
        string $currency,
        string $clusterKey,
        string $cadence,
        int $latestAmountMinor,
        ?int $monthlyEquivalentMinor,
        ?CarbonImmutable $nextExpectedAt,
        bool $confidenceLow,
        array $rows,
    ): void {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $newId = $connection->table('recurring_series')->insertGetId([
            'user_id' => $user->id,
            'direction' => 'expense',
            'detected_name' => $counterparty,
            'state' => 'pending',
            'cadence' => $cadence,
            'latest_amount_minor' => $latestAmountMinor,
            'latest_currency' => $currency,
            'monthly_equivalent_minor' => $monthlyEquivalentMinor,
            'variance_tolerance_percent' => self::DEFAULT_VARIANCE_TOLERANCE_PERCENT,
            'next_expected_at' => $nextExpectedAt?->toDateString(),
            'next_expected_confidence_low' => $confidenceLow,
            'cluster_key' => $clusterKey,
            'cluster_counterparty_key' => $counterparty,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertOccurrenceRows($user->id, $newId, $rows, $currency);

        $this->events->dispatch(new RecurringSeriesDetected(
            seriesId: $newId,
            userId: $user->id,
            direction: 'expense',
            detectedName: $counterparty,
            cadence: $cadence,
        ));

        $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
            userId: $user->id,
            recurringSeriesId: $newId,
            direction: 'expense',
            cadence: $cadence,
            latestAmountMinor: $latestAmountMinor,
            latestCurrency: $currency,
        ));
    }

    /**
     * @param  list<stdClass>  $rows
     */
    private function refreshExistingSeries(
        RecurringSeries $series,
        string $clusterKey,
        string $counterparty,
        string $cadence,
        int $latestAmountMinor,
        string $currency,
        ?int $monthlyEquivalentMinor,
        ?CarbonImmutable $nextExpectedAt,
        bool $confidenceLow,
        array $rows,
        User $user,
    ): void {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $previousCadence = $series->cadence;

        $connection->table('recurring_series')
            ->where('id', $series->id)
            ->update([
                'cadence' => $cadence,
                'cluster_key' => $clusterKey,
                'cluster_counterparty_key' => $counterparty,
                'latest_amount_minor' => $latestAmountMinor,
                'latest_currency' => $currency,
                'monthly_equivalent_minor' => $monthlyEquivalentMinor,
                'next_expected_at' => $nextExpectedAt?->toDateString(),
                'next_expected_confidence_low' => $confidenceLow,
                'updated_at' => $now,
            ]);

        $seriesId = $series->id;
        $this->insertOccurrenceRows($user->id, $seriesId, $rows, $currency);

        if (in_array($series->state, ['approved', 'cadence_changed'], true) && $previousCadence !== $cadence) {
            // Re-load the row so the state machine sees the post-refresh
            // metric values (busy_timeout lock will serialise anyway).
            /** @var RecurringSeries $fresh */
            $fresh = RecurringSeries::query()->findOrFail($seriesId);
            if ($fresh->state === 'approved') {
                $this->stateMachine->transition(
                    $fresh,
                    'cadence_changed',
                    'detector_cadence_flip',
                    'detector',
                );

                $this->events->dispatch(new RecurringSeriesCadenceFlipped(
                    seriesId: $seriesId,
                    userId: $user->id,
                    oldCadence: $previousCadence,
                    newCadence: $cadence,
                ));
            }
        }

        $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
            userId: $user->id,
            recurringSeriesId: $seriesId,
            direction: 'expense',
            cadence: $cadence,
            latestAmountMinor: $latestAmountMinor,
            latestCurrency: $currency,
        ));
    }

    /**
     * @param  list<stdClass>  $rows
     */
    private function insertOccurrenceRows(int $userId, int $seriesId, array $rows, string $currency): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'user_id' => $userId,
                'recurring_series_id' => $seriesId,
                'transaction_id' => self::toInt($row->id),
                'observed_at' => self::toStringNullable($row->posted_at),
                'observed_amount_minor' => self::toInt($row->amount_minor),
                'observed_currency' => $currency,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload === []) {
            return;
        }

        $connection->table('recurring_series_occurrences')->insertOrIgnore($payload);
    }

    private static function monthlyEquivalent(int $latestAmountMinor, string $cadence): ?int
    {
        return match ($cadence) {
            // 52/12 is the exact weeks-per-month conversion; the
            // rounded literal 4.33 drifted by ~0.07% (€10.00/wk
            // projects to €43.33/mo, not €43.30) on every weekly row.
            'weekly' => (int) round($latestAmountMinor * 52 / 12),
            'monthly' => $latestAmountMinor,
            'quarterly' => (int) round($latestAmountMinor / 3),
            'yearly' => (int) round($latestAmountMinor / 12),
            default => null,
        };
    }

    private static function toStringNullable(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
