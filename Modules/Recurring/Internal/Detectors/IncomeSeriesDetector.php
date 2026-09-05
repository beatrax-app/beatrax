<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Internal\InferredCadence;
use Modules\Recurring\Internal\Support\DerivedSeriesId;
use Modules\Recurring\Internal\Support\SeriesDetectionGate;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Events\RecurringSeriesDetected;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Support\RecurringDetectionWindow;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @link ../../../../.docs/features/recurring/detection-encryption-posture.md
 */
final readonly class IncomeSeriesDetector implements SeriesDetector
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CadenceInferrer $cadenceInferrer,
        private ClusterKeyComposer $clusterKeyComposer,
        private Dispatcher $events,
        private SensitiveColumnCodec $codec,
        private OccurrenceWriter $occurrences,
        private SeriesRefresher $refresher,
        private MerchantDisplayName $merchantNames,
        private CounterpartyKey $counterpartyKey,
        private LoggerInterface $logger,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    // The floor is an amount in the reader's money: a ¥250,000 stipend worth
    // about €1,571 carried the integer 250000 past a floor meaning €2,000. A
    // currency no rate reaches keeps the reader's integer, as before.
    /**
     * @return array<string, int>
     *
     * @link ../../../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md#the-other-half-comparing-two-denominations-as-bare-integers
     */
    private function floorsByCurrency(User $user, int $readerMinor, string $since): array
    {
        $readerCurrency = $this->baseCurrency->forUser($user);

        $currencies = array_values(array_filter(
            array_map(self::toString(...), $this->db->connection()->table('transactions')
                ->where('user_id', $user->id)
                ->where('type', TransactionType::Income->value)
                ->where('posted_at', '>=', $since)
                ->distinct()
                ->pluck('currency')
                ->all()),
            static fn (string $code): bool => $code !== '',
        ));

        $floor = Money::tryOfMinor($readerMinor, $readerCurrency);
        $floors = [];
        foreach ($currencies as $currency) {
            if ($currency === $readerCurrency || $floor === null) {
                $floors[$currency] = $readerMinor;

                continue;
            }

            $converted = $this->fx->convert($floor, $currency, $this->fx->ratesTo([$readerCurrency], $currency));
            $floors[$currency] = $converted?->toMinor() ?? $readerMinor;
        }

        return $floors === [] ? [$readerCurrency => $readerMinor] : $floors;
    }

    public function detectForUser(User $user, ?Session $session = null): void
    {
        // Zero is a value the reader chose: the field admits it, the column
        // comment names it, and the settings copy offers it as the way to
        // switch the floor off. Only a negative is unreadable, and the
        // narrowest answer to one is a floor nothing can fall under.
        $threshold = max(0, $user->recurring_income_min_amount_minor);
        $since = RecurringDetectionWindow::opensOn($user, $this->clock);

        $floors = $this->floorsByCurrency($user, $threshold, $since);

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
            ->where('type', TransactionType::Income->value)
            ->where(function (Builder $overItsOwnFloor) use ($floors): void {
                foreach ($floors as $currency => $floorMinor) {
                    $overItsOwnFloor->orWhere(
                        fn (Builder $bucket): Builder => $bucket
                            ->where('currency', $currency)
                            ->where('amount_minor', '>=', $floorMinor),
                    );
                }
            })
            ->where('posted_at', '>=', $since)
            ->orderBy('posted_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $currency = self::toString($row->currency);
            if ($currency === '') {
                continue;
            }

            $counterpartyKey = $this->payerKeyFor($row, $user, $session);
            if ($counterpartyKey === null) {
                continue;
            }

            $groupKey = $counterpartyKey.'|'.$currency;
            $groups[$groupKey] = $groups[$groupKey] ?? [
                'counterparty_key' => $counterpartyKey,
                'counterparty_normalized' => self::toString($row->counterparty_normalized),
                'currency' => $currency,
                'rows' => [],
            ];
            $groups[$groupKey]['rows'][] = $row;
        }

        $deferred = 0;
        foreach ($groups as $group) {
            $deferred += $this->processCluster(
                $user,
                $group['counterparty_key'],
                $group['counterparty_normalized'],
                $group['currency'],
                $group['rows'],
            ) ? 0 : 1;
        }

        // A keyed cluster with no readable name is held back rather than
        // written as a digest. Silent, it was indistinguishable from a user
        // who simply has no recurring income.
        if ($deferred > 0) {
            $this->logger->warning(
                'IncomeSeriesDetector: clusters held back this sweep because no readable payer name resolved for their counterparty key; the next sweep that can read one picks them up.',
                ['user_id' => $user->id, 'deferred_clusters' => $deferred],
            );
        }
    }

    // IBAN first: banks rewrite the free-form description, the SEPA IBAN is
    // constant. Keyed before it becomes a stored grouping key — a decrypted
    // IBAN written verbatim put the salary payer, the benefits agency and the
    // pension provider back in the clear.
    /**
     * @return string|null null when the row carries no payer to cluster on
     */
    private function payerKeyFor(stdClass $row, User $user, ?Session $session): ?string
    {
        $iban = self::toString($row->counterparty_iban);
        if ($iban !== '' && $session !== null) {
            // Decrypt BEFORE the value becomes a grouping key; a no-op
            // pass-through when the stored value is not encrypted.
            $iban = $this->codec->decryptValue('transactions', 'counterparty_iban', $iban, $user->id, $session)['value'];
        }

        $key = $iban !== ''
            ? $this->counterpartyKey->forIban($iban, $user->id)
            : self::toString($row->counterparty_normalized);

        return $key === '' || $key === CounterpartyKey::NONE ? null : $key;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return bool false only when the cluster qualified but had no readable name
     */
    private function processCluster(
        User $user,
        string $counterpartyKey,
        string $counterpartyNormalized,
        string $currency,
        array $rows,
    ): bool {
        // Keyed on the counterparty identifier, not detected_name: two payroll
        // providers can normalise to the same display string. Read before the
        // cluster qualifies because it carries the tolerance that decides it.
        /** @var RecurringSeries|null $existingByCounterparty */
        $existingByCounterparty = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', Direction::Income->value)
            ->where('cluster_counterparty_key', $counterpartyKey)
            ->where('latest_currency', $currency)
            ->oldest('id')
            ->first();

        $qualified = $this->qualifyCluster(self::toleranceOf($existingByCounterparty), $rows);
        if ($qualified === null) {
            return true;
        }
        [$filtered, $cadenceResult] = $qualified;

        $clusterKey = $this->clusterKeyComposer->compose(
            Direction::Income->value,
            $counterpartyKey,
            $currency,
            $cadenceResult->cadence->value,
        );

        /** @var RecurringSeries|null $existingBySameCluster */
        $existingBySameCluster = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('direction', Direction::Income->value)
            ->where('cluster_key', $clusterKey)
            ->where('latest_currency', $currency)
            ->oldest('id')
            ->first();

        $existing = $existingBySameCluster ?? $existingByCounterparty;

        $latestRow = $filtered[count($filtered) - 1];
        $latestAmount = self::toInt($latestRow->amount_minor);

        $detected = DetectedSeries::fromCadence($clusterKey, $cadenceResult, $latestAmount, $currency, $filtered);

        if ($existing === null) {
            return $this->insertNewSeries($user, $counterpartyNormalized, $counterpartyKey, $detected);
        }

        // Rejection covers the whole (counterparty, currency) pair across every
        // cadence variant. Refreshing a snoozed row would change the amount the
        // user paused on; the next sweep's expiry pass unpauses it first.
        $paused = in_array($existing->state, [RecurringSeriesState::Rejected->value, RecurringSeriesState::Snoozed->value], true);

        if (! $paused) {
            $this->refresher->refresh(
                $existing,
                $counterpartyKey,
                $detected,
                $user,
                Direction::Income->value,
                $this->merchantNames->healed($existing->detected_name, $user->id, $counterpartyNormalized),
            );
        }

        return true;
    }

    private static function toleranceOf(?RecurringSeries $existing): ?int
    {
        if ($existing === null || ! in_array($existing->state, SeriesDetectionGate::TOLERANCE_STATES, true)) {
            return null;
        }

        return $existing->variance_tolerance_percent;
    }

    // Null means the cluster failed one of the qualifying tests and there is
    // nothing to record.
    /**
     * @param  int|null  $existingTolerance  the variance tolerance percent stored on an existing
     *                                       series for this (user, counterparty, currency); honours
     *                                       a user-edited value so a widened tolerance does not
     *                                       fragment the cluster on the next sweep
     * @param  list<stdClass>  $rows
     * @return array{0: list<stdClass>, 1: InferredCadence}|null
     */
    private function qualifyCluster(?int $existingTolerance, array $rows): ?array
    {
        if (count($rows) < SeriesDetectionGate::MIN_OCCURRENCES) {
            return null;
        }

        $filtered = ClusterAmountFilter::keep($rows, $existingTolerance ?? SeriesDetectionGate::DEFAULT_VARIANCE_TOLERANCE_PERCENT);
        if (count($filtered) < SeriesDetectionGate::MIN_OCCURRENCES) {
            return null;
        }

        $timestamps = [];
        foreach ($filtered as $row) {
            $timestamps[] = CarbonImmutable::parse(self::toString($row->posted_at));
        }
        $cadenceResult = $this->cadenceInferrer->infer($timestamps);

        return $cadenceResult->cadence->isRegular() ? [$filtered, $cadenceResult] : null;
    }

    /**
     * @return bool false when the row was deferred for want of a readable name
     */
    private function insertNewSeries(User $user, string $counterpartyNormalized, string $counterpartyKey, DetectedSeries $detected): bool
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        // The clustering key is normalised, and once at-rest encryption is on
        // it is a keyed digest; the review screen is not the place to show
        // either back. A sweep that cannot read a name defers the series to
        // the next one rather than inserting a digest into a shown column.
        $displayName = $this->merchantNames->forStoredKey($user->id, $counterpartyNormalized);
        if ($displayName === null) {
            return false;
        }

        $userId = self::toInt($user->id);
        $newId = DerivedSeriesId::for($userId, Direction::Income->value, $counterpartyKey, $detected->currency);

        $row = [
            'user_id' => $user->id,
            'direction' => Direction::Income->value,
            'detected_name' => $displayName,
            'state' => RecurringSeriesState::Pending->value,
            'cadence' => $detected->cadence->value,
            'latest_amount_minor' => $detected->latestAmountMinor,
            'latest_currency' => $detected->currency,
            'monthly_equivalent_minor' => $detected->monthlyEquivalentMinor,
            'variance_tolerance_percent' => SeriesDetectionGate::DEFAULT_VARIANCE_TOLERANCE_PERCENT,
            'next_expected_at' => $detected->nextExpectedAt?->toDateString(),
            'next_expected_confidence_low' => $detected->confidenceLow,
            'billing_day' => $detected->billingDay,
            'cluster_key' => $detected->clusterKey,
            'cluster_counterparty_key' => $counterpartyKey,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $connection->table('recurring_series')->insert(['id' => $newId] + $row);

        // Before the occurrences: each one names this series through a NOT NULL
        // foreign key, so a peer receiving the child op first has nothing to
        // hang it on. `$row` omits `id` — the pk carries it, which is the same
        // create-op shape the pairing backfill emits.
        $this->events->dispatch(new EntityMutated(
            table: 'recurring_series',
            pk: $newId,
            userId: $userId,
            mutationType: 'create',
            dirtyFields: $row,
        ));

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

        return true;
    }
}
