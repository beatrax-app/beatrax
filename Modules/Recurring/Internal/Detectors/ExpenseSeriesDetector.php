<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Events\RecurringSeriesDetected;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use stdClass;

/**
 * @phpstan-type SeriesIndex array{cluster: array<string, RecurringSeries>, counterparty: array<string, RecurringSeries>, tolerance: array<string, int>}
 */
final class ExpenseSeriesDetector implements SeriesDetector
{
    use CoercesScalars;

    private const DEFAULT_WINDOW_MONTHS = 2;

    private const DEFAULT_VARIANCE_TOLERANCE_PERCENT = 25;

    private const MIN_OCCURRENCES = 2;

    private const TOLERANCE_STATES = [
        RecurringSeriesState::Pending->value,
        RecurringSeriesState::Approved->value,
        RecurringSeriesState::CadenceChanged->value,
        RecurringSeriesState::Snoozed->value,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CadenceInferrer $cadenceInferrer,
        private readonly ClusterKeyComposer $clusterKeyComposer,
        private readonly Dispatcher $events,
        private readonly OccurrenceWriter $occurrences,
        private readonly SeriesRefresher $refresher,
        private readonly MerchantDisplayName $merchantNames,
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
            ->whereIn('type', [TransactionType::Expense->value, TransactionType::Fee->value, TransactionType::Refund->value])
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

        $currencies = [];
        foreach ($groups as $group) {
            $currencies[] = $group['currency'];
        }

        $index = $this->existingSeriesIndex($user, array_values(array_unique($currencies)));

        foreach ($groups as $group) {
            $this->processCluster($user, $group['counterparty_normalized'], $group['currency'], $group['rows'], $index);
        }
    }

    // Three separate lookups per cluster is three queries per distinct
    // merchant, and every answer they can give lives in this user's own
    // expense series — which is one read for the whole sweep.
    /**
     * @param  list<string>  $currencies
     * @return SeriesIndex
     */
    private function existingSeriesIndex(User $user, array $currencies): array
    {
        $index = self::emptyIndex();
        if ($currencies === []) {
            return $index;
        }

        // Ascending id is the order an index scan on either key hands back, so
        // the row a lookup lands on here is the row `first()` lands on there.
        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('direction', Direction::Expense->value)
            ->whereIn('latest_currency', $currencies)
            ->orderBy('id')
            ->get()
            ->all();

        foreach (RecurringSeries::hydrate(array_map(
            static fn (stdClass $row): array => (array) $row,
            $rows,
        )) as $row) {
            self::indexSeries($index, $row);
        }

        return $index;
    }

    /**
     * @return SeriesIndex
     */
    private static function emptyIndex(): array
    {
        return ['cluster' => [], 'counterparty' => [], 'tolerance' => []];
    }

    /**
     * @param  SeriesIndex  $index
     */
    private static function indexSeries(array &$index, RecurringSeries $row): void
    {
        $clusterKey = self::indexKey($row->cluster_key, $row->latest_currency);
        if (! array_key_exists($clusterKey, $index['cluster'])) {
            $index['cluster'][$clusterKey] = $row;
        }

        $counterpartyKey = $row->cluster_counterparty_key;
        if ($counterpartyKey === null) {
            return;
        }

        $key = self::indexKey($counterpartyKey, $row->latest_currency);
        if (! array_key_exists($key, $index['counterparty'])) {
            $index['counterparty'][$key] = $row;
        }

        if (in_array($row->state, self::TOLERANCE_STATES, true) && ! array_key_exists($key, $index['tolerance'])) {
            $index['tolerance'][$key] = $row->variance_tolerance_percent;
        }
    }

    private static function indexKey(string $key, string $currency): string
    {
        return $key."\0".$currency;
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  SeriesIndex  $index
     */
    private function processCluster(User $user, string $counterparty, string $currency, array $rows, array &$index): void
    {
        $counterpartyKey = self::indexKey($counterparty, $currency);
        $qualified = $this->qualifyCluster($index['tolerance'][$counterpartyKey] ?? null, $rows);
        if ($qualified === null) {
            return;
        }
        [$filtered, $cadenceResult] = $qualified;

        $clusterKey = $this->clusterKeyComposer->compose(
            Direction::Expense->value,
            $counterparty,
            $currency,
            $cadenceResult['cadence']->value,
        );

        // cluster_key encodes cadence + currency, so this only matches a series
        // whose cadence has not moved. A flip falls through to the second
        // lookup, which carries no cadence and refreshes rather than duplicates.
        $existing = $index['cluster'][self::indexKey($clusterKey, $currency)] ?? null;
        $existing ??= $index['counterparty'][$counterpartyKey] ?? null;

        $latestRow = $filtered[count($filtered) - 1];
        $latestAmount = self::toInt($latestRow->amount_minor);

        $detected = DetectedSeries::fromCadence($clusterKey, $cadenceResult, $latestAmount, $currency, $filtered);

        if ($existing === null) {
            $this->insertNewSeries($user, $counterparty, $detected, $index);

            return;
        }

        // Rejection covers the whole (counterparty, currency) pair across every
        // cadence variant. Refreshing a snoozed row would change the amount the
        // user paused on; the next sweep's expiry pass unpauses it first.
        if (in_array($existing->state, [RecurringSeriesState::Rejected->value, RecurringSeriesState::Snoozed->value], true)) {
            return;
        }

        $this->refresher->refresh(
            $existing,
            $counterparty,
            $detected,
            $user,
            Direction::Expense->value,
            $this->merchantNames->healed($existing->detected_name, $user->id, $counterparty),
        );

        self::reindexRefreshed($index, $existing, $clusterKey, $currency);
    }

    // A refresh rewrites cluster_key and latest_currency on the row, and a
    // later cluster in the same sweep reads the index rather than the table.
    /**
     * @param  SeriesIndex  $index
     */
    private static function reindexRefreshed(array &$index, RecurringSeries $existing, string $clusterKey, string $currency): void
    {
        $staleKey = self::indexKey($existing->cluster_key, $existing->latest_currency);
        if (($index['cluster'][$staleKey] ?? null) === $existing) {
            unset($index['cluster'][$staleKey]);
        }

        $existing->cluster_key = $clusterKey;
        $existing->latest_currency = $currency;
        self::indexSeries($index, $existing);
    }

    // Null means the cluster failed one of the qualifying tests and there is
    // nothing to record.
    /**
     * @param  int|null  $existingTolerance  the variance tolerance percent stored on an existing
     *                                       series for this (user, counterparty, currency); honours
     *                                       a user-edited value so a widened tolerance does not
     *                                       fragment the cluster on the next sweep
     * @param  list<stdClass>  $rows
     * @return array{0: list<stdClass>, 1: array{cadence: SeriesCadence, median_interval_days: float, next_expected_at: ?CarbonImmutable, confidence_low: bool, missed_count: int}}|null
     */
    private function qualifyCluster(?int $existingTolerance, array $rows): ?array
    {
        if (count($rows) < self::MIN_OCCURRENCES) {
            return null;
        }

        $tolerance = $existingTolerance ?? self::DEFAULT_VARIANCE_TOLERANCE_PERCENT;
        $filtered = self::applyVarianceFilter($rows, $tolerance);
        if (count($filtered) < self::MIN_OCCURRENCES) {
            return null;
        }

        $timestamps = [];
        foreach ($filtered as $row) {
            $timestamps[] = CarbonImmutable::parse(self::toString($row->posted_at));
        }
        $cadenceResult = $this->cadenceInferrer->infer($timestamps);

        return $cadenceResult['cadence']->isRegular() ? [$filtered, $cadenceResult] : null;
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
     * @param  SeriesIndex  $index
     */
    private function insertNewSeries(User $user, string $counterparty, DetectedSeries $detected, array &$index): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        // The clustering key is normalised, and once at-rest encryption is on
        // it is a keyed digest; the review screen is not the place to show
        // either back. A sweep that cannot read a name defers the series to
        // the next one rather than inserting a digest into a shown column.
        $displayName = $this->merchantNames->forStoredKey($user->id, $counterparty);
        if ($displayName === null) {
            return;
        }

        $newId = $connection->table('recurring_series')->insertGetId([
            'user_id' => $user->id,
            'direction' => Direction::Expense->value,
            'detected_name' => $displayName,
            'state' => RecurringSeriesState::Pending->value,
            'cadence' => $detected->cadence->value,
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

        // Two merchant keys can normalise onto one cluster_key — an ampersand
        // and a space both become a hyphen — so a later cluster in this sweep
        // has to find the row just written instead of inserting over it.
        $inserted = RecurringSeries::query()->find($newId);
        if ($inserted !== null) {
            self::indexSeries($index, $inserted);
        }

        $this->occurrences->write($user->id, $newId, $detected->rows, $detected->currency);

        $this->events->dispatch(new RecurringSeriesDetected(
            seriesId: $newId,
            userId: $user->id,
            direction: Direction::Expense->value,
            detectedName: $displayName,
            cadence: $detected->cadence->value,
        ));

        $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
            userId: $user->id,
            recurringSeriesId: $newId,
            direction: Direction::Expense->value,
            cadence: $detected->cadence->value,
            latestAmountMinor: $detected->latestAmountMinor,
            latestCurrency: $detected->currency,
        ));
    }
}
