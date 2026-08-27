<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Support\TaggedRowScope;
use Modules\Tax\Public\Dto\TaxYearData;

/**
 * @link ../../../../.docs/features/tax/tax-year-resolution.md
 */
final class TaxYearQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly CrossCurrencyTotal $fx,
        private readonly BaseCurrency $baseCurrency,
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
            ->orderBy('t.booked_at')
            ->select([
                'tag.id AS tag_id',
                'tag.transaction_split_id',
                't.id AS transaction_id',
                't.booked_at',
                $connection->raw(TaggedRowScope::SETTLED_AMOUNT_MINOR.' AS settled_amount_minor'),
                't.settled_currency',
                't.settled_amount_minor AS parent_settled_amount_minor',
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
        /** @var array<int|string, array{id: int, name: string|null, shortName: string|null, subtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>}> $groups */
        $groups = [];
        /** @var array{id: null, name: null, shortName: null, subtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>}|null $noCategory */
        $noCategory = null;

        /** @var array<string, int> $deductionsByCurrency */
        $deductionsByCurrency = [];
        /** @var array<string, int> $incomeByCurrency */
        $incomeByCurrency = [];

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

            $rowData = $this->mapRow($row, $userId, $signedMinor);

            if ($row->category_id === null) {
                $noCategory = $this->accumulateNoCategory($noCategory, $rowData, $magnitudeMinor, $currency);
            } else {
                $groups = $this->accumulateCategory($groups, $row, $rowData, $magnitudeMinor, $currency);
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

        $deductions = $this->fx->withRates($deductionsByCurrency, $baseCurrency, $rates);
        $income = $this->fx->withRates($incomeByCurrency, $baseCurrency, $rates);

        $unconverted = array_values(array_unique([...$deductions->unconverted, ...$income->unconverted]));
        sort($unconverted);

        return new TaxYearData(
            year: $year,
            deductionsTotalMinor: $deductions->minor,
            incomeTotalMinor: $income->minor,
            itemCount: $rawRows->count(),
            categories: $this->convertedSubtotals($categories, $baseCurrency, $rates),
            currency: $baseCurrency,
            unconvertedCurrencies: $unconverted,
        );
    }

    /**
     * @param  list<array{id: int|null, name: string|null, shortName: string|null, subtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>}>  $categories
     * @param  array<string, string>  $rates
     * @return list<array<string, mixed>>
     */
    private function convertedSubtotals(array $categories, string $baseCurrency, array $rates): array
    {
        $converted = [];
        foreach ($categories as $category) {
            $category['subtotalMinor'] = $this->fx->withRates($category['byCurrency'], $baseCurrency, $rates)->minor;
            unset($category['byCurrency']);
            $converted[] = $category;
        }

        return $converted;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(\stdClass $row, int $userId, int $signedMinor): array
    {
        // description, display_name, iban and note are ciphertext at rest once
        // encryption is on, so every read-side surface decrypts here.
        return [
            'transactionId' => self::toInt($row->transaction_id),
            'transactionSplitId' => $row->transaction_split_id !== null ? self::toInt($row->transaction_split_id) : null,
            'bookedAt' => self::toStringOrNull($row->booked_at),
            'accountName' => self::toStringOrNull($row->account_name),
            'counterpartyName' => $this->decryptOrNull('counterparties', 'display_name', $row->counterparty_name, $userId),
            'counterpartyIban' => $this->decryptOrNull('counterparties', 'iban', $row->counterparty_iban, $userId),
            'description' => $this->decryptOrNull('transactions', 'description', $row->description, $userId),
            'note' => $this->decryptOrNull('tax_transaction_tags', 'note', $row->note, $userId),
            'settledAmountMinor' => $signedMinor,
            'settledCurrency' => self::toString($row->settled_currency),
            'amountMinor' => self::legScopedOriginalMinor($row, $signedMinor),
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
    // amount is the share of the parent's native amount that its settled amount
    // is of the parent's. Integer arithmetic, rounded half away from zero.
    private static function legScopedOriginalMinor(\stdClass $row, int $legSettledMinor): int
    {
        $originalMinor = self::toInt($row->amount_minor);
        $parentSettledMinor = self::toInt($row->parent_settled_amount_minor);

        if ($row->transaction_split_id === null || $parentSettledMinor === 0 || $legSettledMinor === $parentSettledMinor) {
            return $originalMinor;
        }

        $share = intdiv(
            abs($originalMinor) * abs($legSettledMinor) * 2 + abs($parentSettledMinor),
            abs($parentSettledMinor) * 2,
        );

        return $originalMinor < 0 ? -$share : $share;
    }

    /**
     * @param  array{id: null, name: null, shortName: null, subtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>}|null  $noCategory
     * @param  array<string, mixed>  $rowData
     * @return array{id: null, name: null, shortName: null, subtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>}
     */
    private function accumulateNoCategory(?array $noCategory, array $rowData, int $magnitudeMinor, string $currency): array
    {
        $noCategory ??= [
            'id' => null,
            'name' => null,
            'shortName' => null,
            'subtotalMinor' => 0,
            'rows' => [],
            'byCurrency' => [],
        ];

        $noCategory['byCurrency'][$currency] = ($noCategory['byCurrency'][$currency] ?? 0) + $magnitudeMinor;
        $noCategory['rows'][] = $rowData;

        return $noCategory;
    }

    /**
     * @param  array<int|string, array{id: int, name: string|null, shortName: string|null, subtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>}>  $groups
     * @param  array<string, mixed>  $rowData
     * @return array<int|string, array{id: int, name: string|null, shortName: string|null, subtotalMinor: int, rows: list<array<string,mixed>>, byCurrency: array<string, int>}>
     */
    private function accumulateCategory(array $groups, \stdClass $row, array $rowData, int $magnitudeMinor, string $currency): array
    {
        $catKey = (string) self::toInt($row->category_id);
        if (! array_key_exists($catKey, $groups)) {
            $groups[$catKey] = [
                'id' => self::toInt($row->category_id),
                'name' => self::toStringOrNull($row->category_name),
                'shortName' => self::toStringOrNull($row->category_short_name),
                'subtotalMinor' => 0,
                'rows' => [],
                'byCurrency' => [],
            ];
        }

        $groups[$catKey]['byCurrency'][$currency] = ($groups[$catKey]['byCurrency'][$currency] ?? 0) + $magnitudeMinor;
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
}
