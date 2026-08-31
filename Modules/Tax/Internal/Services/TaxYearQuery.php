<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\RowChunk;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Support\TaggedRowScope;
use Modules\Tax\Public\Dto\TaxYearData;

/**
 * @link ../../../../.docs/features/tax/tax-year-resolution.md
 */
final readonly class TaxYearQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private CrossCurrencyTotal $fx,
        private BaseCurrency $baseCurrency,
    ) {}

    public function forUser(int $userId, int $year): TaxYearData
    {
        $rawRows = $this->fetchTaggedRows($userId, $year);

        if ($rawRows->isEmpty()) {
            return new TaxYearData(
                year: $year,
                deductionsTotalMinor: 0,
                incomeTotalMinor: 0,
                itemCount: 0,
                categories: [],
                currency: $this->baseCurrency->code(),
            );
        }

        return $this->buildYearData($userId, $year, $rawRows);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function fetchTaggedRows(int $userId, int $year): Collection
    {
        $connection = $this->db->connection();

        $query = $connection
            ->table(TaggedRowScope::TAGS)
            ->join(TaggedRowScope::TRANSACTIONS, 't.id', '=', 'tag.transaction_id')
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->leftJoin('accounts AS a', 'a.id', '=', 't.account_id')
            ->leftJoin('counterparties AS cp', 'cp.id', '=', 't.counterparty_id')
            ->where('tag.user_id', $userId)
            ->whereRaw(TaggedRowScope::EFFECTIVE_YEAR.' = ?', [$year]);

        TaggedRowScope::joinLegs($query);
        TaggedRowScope::withoutSuperseded($query, $connection);

        return $query
            ->orderBy('cat.sort_order')
            // Ordered on the column the rows print, and tie-broken on `id`
            // because a DATE leaves far more ties than the DATETIME this used
            // to sort on — without it two rows on one day swap places run to
            // run, and the CSV an accountant diffs is never the same twice.
            ->orderBy('t.posted_at')
            ->orderBy('t.id')
            ->select([
                'tag.id AS tag_id',
                'tag.transaction_split_id',
                't.id AS transaction_id',
                't.posted_at',
                $connection->raw(TaggedRowScope::SETTLED_AMOUNT_MINOR.' AS settled_amount_minor'),
                't.settled_currency',
                't.amount_minor',
                't.currency',
                't.description',
                't.source_format',
                't.import_run_id',
                't.fingerprint',
                't.type AS transaction_type',
                'tag.note',
                'tag.tax_year_override',
                'cat.id AS category_id',
                'cat.name AS category_name',
                'cat.short_name AS category_short_name',
                'a.name AS account_name',
                'cp.display_name AS counterparty_name',
                'cp.metadata AS counterparty_metadata',
                'cp.iban AS counterparty_iban',
            ])
            ->get();
    }

    // A tagged row is worth what its own settled_currency says, so every total
    // here buckets by currency and converts each bucket before adding. The
    // magnitude carried down into the groups is the bucket key too, so a
    // category subtotal is converted on the same terms as the year's.
    /**
     * @param  Collection<int, \stdClass>  $rawRows
     */
    private function buildYearData(int $userId, int $year, Collection $rawRows): TaxYearData
    {
        /** @var array<int|string, array{id: int, name: string|null, shortName: string|null, subtotalMinor: int, incomeSubtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>, incomeByCurrency: array<string, int>}> $groups */
        $groups = [];
        /** @var array{id: null, name: null, shortName: null, subtotalMinor: int, incomeSubtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>, incomeByCurrency: array<string, int>}|null $noCategory */
        $noCategory = null;

        /** @var array<string, int> $deductionsByCurrency */
        $deductionsByCurrency = [];
        /** @var array<string, int> $incomeByCurrency */
        $incomeByCurrency = [];

        $legNativeMinor = $this->legNativeMinorByLeg($rawRows);

        foreach ($rawRows as $row) {
            $signedMinor = self::toInt($row->settled_amount_minor);
            $magnitudeMinor = abs($signedMinor);
            $currency = self::toString($row->settled_currency);
            $isIncome = self::toString($row->transaction_type) === TransactionType::Income->value;

            if ($isIncome) {
                $incomeByCurrency[$currency] = ($incomeByCurrency[$currency] ?? 0) + $magnitudeMinor;
            } else {
                $deductionsByCurrency[$currency] = ($deductionsByCurrency[$currency] ?? 0) + $magnitudeMinor;
            }

            $rowData = $this->mapRow($row, $userId, $signedMinor, $legNativeMinor);

            if ($row->category_id === null) {
                $noCategory = $this->accumulateNoCategory($noCategory, $rowData, $magnitudeMinor, $currency, $isIncome);
            } else {
                $groups = $this->accumulateCategory($groups, $row, $rowData, $magnitudeMinor, $currency, $isIncome);
            }
        }

        $categories = array_values($groups);
        if ($noCategory !== null) {
            $categories[] = $noCategory;
        }

        $baseCurrency = $this->baseCurrency->code();
        $rates = $this->fx->ratesTo(
            array_merge(array_keys($deductionsByCurrency), array_keys($incomeByCurrency)),
            $baseCurrency,
        );

        // The headline is what the sections add up to, not a second conversion
        // of the same rows: converting the year's bucket and each section's
        // slice of it separately rounded them apart, and three sections printed
        // a cent under the figure above them.
        $deductions = $this->distributedSubtotals(array_column($categories, 'byCurrency'), $baseCurrency, $rates);
        $income = $this->distributedSubtotals(array_column($categories, 'incomeByCurrency'), $baseCurrency, $rates);

        $unconverted = array_values(array_unique([...$deductions['unconverted'], ...$income['unconverted']]));
        sort($unconverted);

        return new TaxYearData(
            year: $year,
            deductionsTotalMinor: $deductions['total'],
            incomeTotalMinor: $income['total'],
            itemCount: $rawRows->count(),
            categories: self::withSubtotals($categories, $deductions['subtotals'], $income['subtotals']),
            currency: $baseCurrency,
            unconvertedCurrencies: $unconverted,
        );
    }

    // Grouped by currency, never by section: CrossCurrencyTotal::distribute()
    // converts each currency's whole bucket once and hands the difference back
    // to the sections, so they cannot stop summing to their own headline.
    /**
     * @param  list<array<string, int>>  $bucketsBySection  section index => currency => magnitude minor
     * @param  array<string, string>  $rates
     * @return array{subtotals: array<int, int>, total: int, unconverted: list<string>}
     */
    private function distributedSubtotals(array $bucketsBySection, string $baseCurrency, array $rates): array
    {
        /** @var array<string, array<int, int>> $byCurrency */
        $byCurrency = [];
        foreach ($bucketsBySection as $index => $buckets) {
            foreach ($buckets as $currency => $minor) {
                $byCurrency[$currency][$index] = ($byCurrency[$currency][$index] ?? 0) + $minor;
            }
        }

        $subtotals = array_fill(0, count($bucketsBySection), 0);
        $total = 0;
        $unconverted = [];

        foreach ($byCurrency as $currency => $parts) {
            $converted = $this->fx->distribute($parts, $currency, $baseCurrency, $rates);

            if ($converted === null) {
                $unconverted[] = $currency;

                continue;
            }

            foreach ($converted as $index => $minor) {
                $subtotals[$index] += $minor;
                $total += $minor;
            }
        }

        return ['subtotals' => $subtotals, 'total' => $total, 'unconverted' => $unconverted];
    }

    // The section subtotal answers the "Total deductions" headline it sits
    // under, so an income row filed against a deduction category is counted
    // beside it and never inside it.
    /**
     * @param  list<array{id: int|null, name: string|null, shortName: string|null, subtotalMinor: int, incomeSubtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>, incomeByCurrency: array<string, int>}>  $categories
     * @param  array<int, int>  $deductionSubtotals
     * @param  array<int, int>  $incomeSubtotals
     * @return list<array<string, mixed>>
     */
    private static function withSubtotals(array $categories, array $deductionSubtotals, array $incomeSubtotals): array
    {
        $converted = [];
        foreach ($categories as $index => $category) {
            $category['subtotalMinor'] = $deductionSubtotals[$index] ?? 0;
            $category['incomeSubtotalMinor'] = $incomeSubtotals[$index] ?? 0;
            unset($category['byCurrency'], $category['incomeByCurrency']);
            $converted[] = $category;
        }

        return $converted;
    }

    /**
     * @param  array<int, int>  $legNativeMinor  leg id => native minor, as apportioned across the parent's whole leg set
     * @return array<string, mixed>
     */
    private function mapRow(\stdClass $row, int $userId, int $signedMinor, array $legNativeMinor): array
    {
        // description, display_name, iban and note are ciphertext at rest once
        // encryption is on, so every read-side surface decrypts here.
        return [
            'transactionId' => self::toInt($row->transaction_id),
            'transactionSplitId' => $row->transaction_split_id !== null ? self::toInt($row->transaction_split_id) : null,
            'postedAt' => self::toStringOrNull($row->posted_at),
            'accountName' => self::toStringOrNull($row->account_name),
            'counterpartyName' => $this->counterpartyName($row, $userId),
            'counterpartyIban' => $this->decryptOrNull('counterparties', 'iban', $row->counterparty_iban, $userId),
            'description' => $this->decryptOrNull('transactions', 'description', $row->description, $userId),
            'note' => $this->decryptOrNull('tax_transaction_tags', 'note', $row->note, $userId),
            'settledAmountMinor' => $signedMinor,
            'settledCurrency' => self::toString($row->settled_currency),
            'amountMinor' => self::legScopedOriginalMinor($row, $legNativeMinor),
            'currency' => self::toString($row->currency),
            'transactionType' => self::toString($row->transaction_type),
            'categoryId' => $row->category_id !== null ? self::toInt($row->category_id) : null,
            'categoryName' => self::toStringOrNull($row->category_name),
            'categoryShortName' => self::toStringOrNull($row->category_short_name),
            'taxYearOverride' => $row->tax_year_override !== null ? self::toInt($row->tax_year_override) : null,
            'sourceFormat' => self::toString($row->source_format),
            'importRunId' => self::toInt($row->import_run_id),
            'fingerprint' => self::toString($row->fingerprint),
        ];
    }

    // transaction_splits carries only the settled slice, so a leg's native
    // amount is its share of the parent's. A parent with no leg set to
    // apportion against — none stored, or a set summing to zero — reports its
    // own native amount, which is the whole-transaction answer.
    /**
     * @param  array<int, int>  $legNativeMinor
     */
    private static function legScopedOriginalMinor(\stdClass $row, array $legNativeMinor): int
    {
        if ($row->transaction_split_id === null) {
            return self::toInt($row->amount_minor);
        }

        return $legNativeMinor[self::toInt($row->transaction_split_id)] ?? self::toInt($row->amount_minor);
    }

    // Apportioned across the parent's WHOLE leg set, not the tagged subset:
    // the share a leg is given cannot depend on which of its siblings someone
    // tagged, and the remainder goes back to the set the rounding took it from.
    // A tagged leg reports what it would have on a fully tagged page.
    /**
     * @param  Collection<int, \stdClass>  $rawRows
     * @return array<int, int> leg id => native minor
     */
    private function legNativeMinorByLeg(Collection $rawRows): array
    {
        /** @var array<int, int> $nativeByTransaction */
        $nativeByTransaction = [];
        foreach ($rawRows as $row) {
            if ($row->transaction_split_id !== null) {
                $nativeByTransaction[self::toInt($row->transaction_id)] = self::toInt($row->amount_minor);
            }
        }

        $native = [];
        foreach ($this->legWeightsByTransaction(array_keys($nativeByTransaction)) as $transactionId => $weights) {
            $shares = CrossCurrencyTotal::apportion($nativeByTransaction[$transactionId], $weights);

            foreach ($shares ?? [] as $legId => $legMinor) {
                $native[$legId] = $legMinor;
            }
        }

        return $native;
    }

    /**
     * @param  list<int>  $transactionIds
     * @return array<int, array<int, int>> transaction id => leg id => settled minor
     */
    private function legWeightsByTransaction(array $transactionIds): array
    {
        $weights = [];

        foreach (array_chunk($transactionIds, RowChunk::DEFAULT_SIZE) as $chunk) {
            $legs = $this->db->connection()
                ->table('transaction_splits')
                ->whereIn('transaction_id', $chunk)
                ->orderBy('transaction_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'transaction_id', 'settled_amount_minor']);

            foreach ($legs as $leg) {
                $weights[self::toInt($leg->transaction_id)][self::toInt($leg->id)] = self::toInt($leg->settled_amount_minor);
            }
        }

        return $weights;
    }

    /**
     * @param  array{id: null, name: null, shortName: null, subtotalMinor: int, incomeSubtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>, incomeByCurrency: array<string, int>}|null  $noCategory
     * @param  array<string, mixed>  $rowData
     * @return array{id: null, name: null, shortName: null, subtotalMinor: int, incomeSubtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>, incomeByCurrency: array<string, int>}
     */
    private function accumulateNoCategory(?array $noCategory, array $rowData, int $magnitudeMinor, string $currency, bool $isIncome): array
    {
        $noCategory ??= [
            'id' => null,
            'name' => null,
            'shortName' => null,
            'subtotalMinor' => 0,
            'incomeSubtotalMinor' => 0,
            'rows' => [],
            'byCurrency' => [],
            'incomeByCurrency' => [],
        ];

        $bucket = $isIncome ? 'incomeByCurrency' : 'byCurrency';
        $noCategory[$bucket][$currency] = ($noCategory[$bucket][$currency] ?? 0) + $magnitudeMinor;
        $noCategory['rows'][] = $rowData;

        return $noCategory;
    }

    /**
     * @param  array<int|string, array{id: int, name: string|null, shortName: string|null, subtotalMinor: int, incomeSubtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>, incomeByCurrency: array<string, int>}>  $groups
     * @param  array<string, mixed>  $rowData
     * @return array<int|string, array{id: int, name: string|null, shortName: string|null, subtotalMinor: int, incomeSubtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>, incomeByCurrency: array<string, int>}>
     */
    private function accumulateCategory(array $groups, \stdClass $row, array $rowData, int $magnitudeMinor, string $currency, bool $isIncome): array
    {
        $catKey = (string) self::toInt($row->category_id);
        if (! array_key_exists($catKey, $groups)) {
            $groups[$catKey] = [
                'id' => self::toInt($row->category_id),
                'name' => self::toStringOrNull($row->category_name),
                'shortName' => self::toStringOrNull($row->category_short_name),
                'subtotalMinor' => 0,
                'incomeSubtotalMinor' => 0,
                'rows' => [],
                'byCurrency' => [],
                'incomeByCurrency' => [],
            ];
        }

        $bucket = $isIncome ? 'incomeByCurrency' : 'byCurrency';
        $groups[$catKey][$bucket][$currency] = ($groups[$catKey][$bucket][$currency] ?? 0) + $magnitudeMinor;
        $groups[$catKey]['rows'][] = $rowData;

        return $groups;
    }

    /**
     * @return array<int>
     */
    public function availableYears(int $userId): array
    {
        $connection = $this->db->connection();

        $query = $connection
            ->table(TaggedRowScope::TAGS)
            ->join(TaggedRowScope::TRANSACTIONS, 't.id', '=', 'tag.transaction_id')
            ->where('tag.user_id', $userId);

        TaggedRowScope::withoutSuperseded($query, $connection);

        $rows = $query
            ->selectRaw('DISTINCT '.TaggedRowScope::EFFECTIVE_YEAR.' AS effective_year')
            ->orderByRaw('effective_year DESC')
            ->get();

        $years = [];
        foreach ($rows as $row) {
            $years[] = self::toInt($row->effective_year);
        }

        return $years;
    }

    private function decryptOrNull(string $table, string $field, mixed $value, int $userId): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->codec->decryptValue($table, $field, $value, $userId, ($this->session)())['value'];
    }

    // Decrypt first, then translate: the seam reads the plaintext name, and
    // `metadata` is not a sensitive column. Null survives, because a row with
    // no counterparty prints an em dash rather than an empty cell.
    /**
     * @link ../../../../.docs/features/counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name
     */
    private function counterpartyName(\stdClass $row, int $userId): ?string
    {
        $stored = $this->decryptOrNull('counterparties', 'display_name', $row->counterparty_name, $userId);

        return $stored === null ? null : CounterpartyDefaultName::resolve($stored, $row->counterparty_metadata);
    }
}
