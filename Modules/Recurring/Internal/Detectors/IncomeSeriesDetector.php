<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesDetected;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * Income-side recurring series detector.
 *
 * Reads transactions of type `income` inside the user's
 * `recurring_detection_window_months` look-back window. Filters out
 * any row below `recurring_income_min_amount_minor` (default
 * 200000 minor units = €2000) so small refunds and cashbacks never
 * pollute the income-series surface.
 *
 * Clusters rows by counterparty IBAN first — when the IBAN is non-null
 * and non-empty the cluster key is the IBAN; otherwise the detector
 * falls back to `counterparty_normalized`. The currency token is
 * carried alongside so a same-employer EUR + USD inflow splits into
 * two distinct series per the original-currency clustering rule.
 *
 * Cluster keys are composed with `direction='income'` so the
 * `(user_id, direction, cluster_key, latest_currency)` UNIQUE constraint
 * holds independently from any expense cluster on the same counterparty.
 *
 * Rejected income series stay rejected — a `state='rejected'` row is
 * never re-prompted even when a new clustered run would otherwise
 * promote it. Cadence-flip detection on approved rows mirrors the
 * expense detector: state machine transition + RecurringSeriesCadenceFlipped
 * event.
 *
 * Occurrence rows land via INSERT-OR-IGNORE on the
 * `(recurring_series_id, transaction_id)` UNIQUE constraint so re-runs
 * of the sweep do not duplicate the per-occurrence ledger.
 *
 * **CRYPT-01 (14.1-08, D-06):** `counterparty_iban` is a
 * `SensitiveFieldRegistry`-listed column — at rest under an encrypted
 * user it is random-nonce ciphertext that differs per row even for the
 * SAME logical IBAN, so clustering on the raw stored value would
 * scatter every income row into its own one-row, sub-threshold group
 * and silently detect nothing. `$session` (optional — see below) is
 * decrypted-through via {@see SensitiveColumnCodec::decryptValue()}
 * BEFORE the value participates in cluster-key derivation. The codec
 * call is itself a documented no-op pass-through for a plaintext
 * (pre-encryption or never-encrypted) value, so passing a session is
 * always safe.
 *
 * The `$session` parameter is deliberately optional and NOT part of
 * the {@see SeriesDetector} contract (PHP permits an implementing
 * method to add extra default-valued parameters without breaking
 * interface conformance) — {@see DetectRecurringSeriesJob}
 * is the ONLY caller that knows which dispatch origin it is running
 * under (in-request `dispatchSync`, KEK present, vs the KEK-less
 * scheduled daemon) and decides whether to pass a session at all;
 * every OTHER caller resolving this class through the generic
 * `SeriesDetector` interface still compiles and runs unchanged.
 */
final class IncomeSeriesDetector implements SeriesDetector
{
    private const DEFAULT_WINDOW_MONTHS = 2;

    private const DEFAULT_MIN_AMOUNT_MINOR = 200000;

    private const MIN_OCCURRENCES = 2;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CadenceInferrer $cadenceInferrer,
        private readonly ClusterKeyComposer $clusterKeyComposer,
        private readonly RecurringSeriesStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
    ) {}

    /**
     * @param  Session|null  $session  when non-null, `counterparty_iban` is
     *                                 decrypted (codec no-op for a
     *                                 plaintext/non-encrypted value) before
     *                                 clustering. Null (the default) skips
     *                                 the decrypt call entirely — used by
     *                                 the generic `SeriesDetector` interface
     *                                 call shape and by any caller with no
     *                                 session context.
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
            $counterparty = self::toStringNullable($row->counterparty_normalized);
            $iban = self::toStringNullable($row->counterparty_iban);
            if ($iban !== '' && $session !== null) {
                // CRYPT-01: decrypt BEFORE the value becomes the cluster
                // key — a no-op pass-through when the value is already
                // plaintext (not encrypted / no epoch verifies).
                $iban = $this->codec->decryptValue('transactions', 'counterparty_iban', $iban, $user->id, $session)['value'];
            }
            $currency = self::toStringNullable($row->currency);
            if ($currency === '') {
                continue;
            }

            // IBAN-primary cluster key with normalized-description
            // fallback. An IBAN identifies the upstream payer
            // (employer, freelance client) more stably than the free-
            // form description — banks rewrite the description text
            // over time but the SEPA IBAN is constant.
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
        if (count($rows) < self::MIN_OCCURRENCES) {
            return;
        }

        $timestamps = [];
        foreach ($rows as $row) {
            $timestamps[] = CarbonImmutable::parse(self::toStringNullable($row->posted_at));
        }
        $cadenceResult = $this->cadenceInferrer->infer($timestamps);
        if ($cadenceResult['cadence'] === 'irregular') {
            return;
        }

        $clusterKey = $this->clusterKeyComposer->compose(
            'income',
            $counterpartyKey,
            $currency,
            $cadenceResult['cadence'],
        );

        /** @var RecurringSeries|null $existingBySameCluster */
        $existingBySameCluster = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', 'income')
            ->where('cluster_key', $clusterKey)
            ->where('latest_currency', $currency)
            ->first();

        // Cadence-flip fallback: when `cluster_key` misses because the
        // cadence band has flipped, look up by the persisted
        // counterparty key (IBAN when present, otherwise the
        // normalized counterparty description). Keying on the
        // counterparty identifier (not on detected_name) is what
        // keeps two payroll providers that share a normalized display
        // string from collapsing into one another.
        /** @var RecurringSeries|null $existingByCounterparty */
        $existingByCounterparty = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', 'income')
            ->where('cluster_counterparty_key', $counterpartyKey)
            ->where('latest_currency', $currency)
            ->first();

        $existing = $existingBySameCluster ?? $existingByCounterparty;

        $latestRow = $rows[count($rows) - 1];
        $latestAmount = self::toInt($latestRow->amount_minor);
        $monthlyEquivalent = self::monthlyEquivalent($latestAmount, $cadenceResult['cadence']);
        $nextExpectedAt = $cadenceResult['next_expected_at'];

        if ($existing === null) {
            $this->insertNewSeries(
                $user,
                $counterpartyNormalized,
                $counterpartyKey,
                $currency,
                $clusterKey,
                $cadenceResult['cadence'],
                $latestAmount,
                $monthlyEquivalent,
                $nextExpectedAt,
                $cadenceResult['confidence_low'],
                $rows,
            );

            return;
        }

        if ($existing->state === 'rejected') {
            // Rejection covers the entire (counterparty, currency)
            // pair — every cadence variant. The lookup hits via
            // cluster_counterparty_key + latest_currency, so a
            // freshly-clustering quarterly pattern for an income
            // source the user previously rejected at a monthly
            // cadence is intentionally suppressed. The user
            // un-rejects from the review queue to bring the
            // counterparty back; partial cadence-only un-rejection
            // is not supported.
            return;
        }

        if ($existing->state === 'snoozed') {
            // Snooze is a "hide for now" affordance; refreshing the
            // metrics in the background would surface a different
            // amount than the one the user paused on. The
            // snooze-expiry pass on the next sweep flips snoozed →
            // pending and the next normal refresh then runs.
            return;
        }

        $this->refreshExistingSeries(
            $existing,
            $clusterKey,
            $counterpartyKey,
            $cadenceResult['cadence'],
            $latestAmount,
            $currency,
            $monthlyEquivalent,
            $nextExpectedAt,
            $cadenceResult['confidence_low'],
            $rows,
            $user,
        );
    }

    /**
     * @param  list<stdClass>  $rows
     */
    private function insertNewSeries(
        User $user,
        string $counterpartyNormalized,
        string $counterpartyKey,
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
            'direction' => 'income',
            'detected_name' => $counterpartyNormalized,
            'state' => 'pending',
            'cadence' => $cadence,
            'latest_amount_minor' => $latestAmountMinor,
            'latest_currency' => $currency,
            'monthly_equivalent_minor' => $monthlyEquivalentMinor,
            'variance_tolerance_percent' => 25,
            'next_expected_at' => $nextExpectedAt?->toDateString(),
            'next_expected_confidence_low' => $confidenceLow,
            'cluster_key' => $clusterKey,
            'cluster_counterparty_key' => $counterpartyKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertOccurrenceRows($user->id, $newId, $rows, $currency);

        $this->events->dispatch(new RecurringSeriesDetected(
            seriesId: $newId,
            userId: $user->id,
            direction: 'income',
            detectedName: $counterpartyNormalized,
            cadence: $cadence,
        ));

        $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
            userId: $user->id,
            recurringSeriesId: $newId,
            direction: 'income',
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
        string $counterpartyKey,
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
                'cluster_counterparty_key' => $counterpartyKey,
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
            direction: 'income',
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
