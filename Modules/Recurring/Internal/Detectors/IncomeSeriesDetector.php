<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Events\RecurringSeriesDetected;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/recurring/detection-encryption-posture.md
 */
final class IncomeSeriesDetector implements SeriesDetector
{
    use CoercesScalars;

    private const DEFAULT_WINDOW_MONTHS = 2;

    private const DEFAULT_MIN_AMOUNT_MINOR = 200000;

    private const MIN_OCCURRENCES = 2;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CadenceInferrer $cadenceInferrer,
        private readonly ClusterKeyComposer $clusterKeyComposer,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly OccurrenceWriter $occurrences,
        private readonly SeriesRefresher $refresher,
        private readonly MerchantDisplayName $merchantNames,
    ) {}

    /**
     * @param  Session|null  $session
     */
    public function detectForUser(User $user, ?Session $session = null): void
    {
        $windowMonths = $user->recurring_detection_window_months;
        if ($windowMonths <= 0) {
            $windowMonths = self::DEFAULT_WINDOW_MONTHS;
        }
        $threshold = $user->recurring_income_min_amount_minor;
        if ($threshold <= 0) {
            $threshold = self::DEFAULT_MIN_AMOUNT_MINOR;
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
            ->where('type', 'income')
            ->where('amount_minor', '>=', $threshold)
            ->where('posted_at', '>=', $since)
            ->orderBy('posted_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $counterparty = self::toString($row->counterparty_normalized);
            $iban = self::toString($row->counterparty_iban);
            if ($iban !== '' && $session !== null) {
                // Decrypt BEFORE the value becomes a grouping key; a no-op
                // pass-through when the stored value is not encrypted.
                $iban = $this->codec->decryptValue('transactions', 'counterparty_iban', $iban, $user->id, $session)['value'];
            }
            $currency = self::toString($row->currency);
            if ($currency === '') {
                continue;
            }

            // IBAN-primary cluster key, normalized-description fallback: an
            // IBAN identifies the upstream payer more stably than the
            // free-form description (banks rewrite it; the SEPA IBAN is
            // constant). See the class @link for the plaintext-derivative note.
            $counterpartyKey = $iban !== '' ? $iban : $counterparty;
            if ($counterpartyKey === '') {
                continue;
            }

            $groupKey = $counterpartyKey.'|'.$currency;
            $groups[$groupKey] = $groups[$groupKey] ?? [
                'counterparty_key' => $counterpartyKey,
                'counterparty_normalized' => $counterparty,
                'currency' => $currency,
                'rows' => [],
            ];
            $groups[$groupKey]['rows'][] = $row;
        }

        foreach ($groups as $group) {
            $this->processCluster(
                $user,
                $group['counterparty_key'],
                $group['counterparty_normalized'],
                $group['currency'],
                $group['rows'],
            );
        }
    }

    /**
     * @param  list<stdClass>  $rows
     */
    private function processCluster(
        User $user,
        string $counterpartyKey,
        string $counterpartyNormalized,
        string $currency,
        array $rows,
    ): void {
        $cadenceResult = $this->qualifyCluster($rows);
        if ($cadenceResult === null) {
            return;
        }

        $clusterKey = $this->clusterKeyComposer->compose(
            Direction::Income->value,
            $counterpartyKey,
            $currency,
            $cadenceResult['cadence']->value,
        );

        /** @var RecurringSeries|null $existingBySameCluster */
        $existingBySameCluster = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', Direction::Income->value)
            ->where('cluster_key', $clusterKey)
            ->where('latest_currency', $currency)
            ->first();

        // Cadence-flip fallback keyed on the counterparty identifier (not
        // detected_name) — keeps two payroll providers that share a
        // normalized display string from collapsing into one another.
        /** @var RecurringSeries|null $existingByCounterparty */
        $existingByCounterparty = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', Direction::Income->value)
            ->where('cluster_counterparty_key', $counterpartyKey)
            ->where('latest_currency', $currency)
            ->first();

        $existing = $existingBySameCluster ?? $existingByCounterparty;

        $latestRow = $rows[count($rows) - 1];
        $latestAmount = self::toInt($latestRow->amount_minor);

        $detected = DetectedSeries::fromCadence($clusterKey, $cadenceResult, $latestAmount, $currency, $rows);

        if ($existing === null) {
            $this->insertNewSeries($user, $counterpartyNormalized, $counterpartyKey, $detected);

            return;
        }

        // Both states mean leave this row alone. Rejection covers the whole
        // (counterparty, currency) pair across every cadence variant; a
        // snoozed row would surface a different amount than the one the user
        // paused on, and the next sweep's expiry pass unpauses it first.
        if (in_array($existing->state, [RecurringSeriesState::Rejected->value, RecurringSeriesState::Snoozed->value], true)) {
            return;
        }

        $this->refresher->refresh($existing, $counterpartyKey, $detected, $user, Direction::Income->value);
    }

    // A cluster qualifies once it has enough occurrences and the intervals
    // resolve to a real cadence. Null means one of those two tests failed
    // and there is nothing to record. Income skips the variance filter that
    // expense applies, so the row list is not narrowed here.
    /**
     * @param  list<stdClass>  $rows
     * @return array{cadence: SeriesCadence, median_interval_days: float, next_expected_at: ?CarbonImmutable, confidence_low: bool, missed_count: int}|null
     */
    private function qualifyCluster(array $rows): ?array
    {
        if (count($rows) < self::MIN_OCCURRENCES) {
            return null;
        }

        $timestamps = [];
        foreach ($rows as $row) {
            $timestamps[] = CarbonImmutable::parse(self::toString($row->posted_at));
        }
        $cadenceResult = $this->cadenceInferrer->infer($timestamps);

        return $cadenceResult['cadence']->isRegular() ? $cadenceResult : null;
    }

    private function insertNewSeries(User $user, string $counterpartyNormalized, string $counterpartyKey, DetectedSeries $detected): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        // The clustering key is normalised; the review screen is not the
        // place to show it back. See MerchantDisplayName.
        $displayName = $this->merchantNames->forNormalized($user->id, $counterpartyNormalized) ?? $counterpartyNormalized;

        $newId = $connection->table('recurring_series')->insertGetId([
            'user_id' => $user->id,
            'direction' => Direction::Income->value,
            'detected_name' => $displayName,
            'state' => RecurringSeriesState::Pending->value,
            'cadence' => $detected->cadence->value,
            'latest_amount_minor' => $detected->latestAmountMinor,
            'latest_currency' => $detected->currency,
            'monthly_equivalent_minor' => $detected->monthlyEquivalentMinor,
            'variance_tolerance_percent' => 25,
            'next_expected_at' => $detected->nextExpectedAt?->toDateString(),
            'next_expected_confidence_low' => $detected->confidenceLow,
            'cluster_key' => $detected->clusterKey,
            'cluster_counterparty_key' => $counterpartyKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->occurrences->write($user->id, $newId, $detected->rows, $detected->currency);

        $this->events->dispatch(new RecurringSeriesDetected(
            seriesId: $newId,
            userId: $user->id,
            direction: Direction::Income->value,
            detectedName: $displayName,
            cadence: $detected->cadence->value,
        ));

        $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
            userId: $user->id,
            recurringSeriesId: $newId,
            direction: Direction::Income->value,
            cadence: $detected->cadence->value,
            latestAmountMinor: $detected->latestAmountMinor,
            latestCurrency: $detected->currency,
        ));
    }
}
