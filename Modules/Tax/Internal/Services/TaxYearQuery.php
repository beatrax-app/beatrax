<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Dto\TaxYearData;

// Amounts are always summed from settled_amount_minor (settled EUR); no
// floating-point arithmetic on money values anywhere in this class. See
// the linked doc for year resolution, leg-aware amounts, the
// supersession policy, and category-name resolution.
/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxYearQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    // Rows group by deduction_category_id (NULL rows land in a trailing
    // "no category" group). type='income' counts toward incomeTotalMinor,
    // everything else toward deductionsTotalMinor; abs() converts the
    // signed stored amounts to the UI's unsigned "you spent X" totals.
    public function forUser(int $userId, int $year): TaxYearData
    {
        $connection = $this->db->connection();

        $rawRows = $connection
            ->table('tax_transaction_tags AS tag')
            ->join('transactions AS t', 't.id', '=', 'tag.transaction_id')
            // A leg-scoped tag joins to its own transaction_splits row; a
            // whole-tx tag's ts.* columns are NULL and the COALESCE below
            // falls back to the parent.
            ->leftJoin('transaction_splits AS ts', 'ts.id', '=', 'tag.transaction_split_id')
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->leftJoin('accounts AS a', 'a.id', '=', 't.account_id')
            ->leftJoin('counterparties AS cp', 'cp.id', '=', 't.counterparty_id')
            // User scope is the first filter on every query, never
            // omitted — a structural ownership guard.
            ->where('tag.user_id', $userId)
            // Effective year resolves via the COALESCE override rather
            // than the raw booked date.
            ->whereRaw(
                'COALESCE(tag.tax_year_override, CAST(strftime(\'%Y\', t.booked_at) AS INTEGER)) = ?',
                [$year],
            )
            // SUPERSESSION POLICY: exclude a whole-tx tag row
            // (transaction_split_id IS NULL) when the SAME transaction also
            // has at least one leg-scoped tag row. Leg-scoped rows are
            // always included.
            ->where(function (QueryBuilder $q) use ($connection): void {
                $q->whereNotNull('tag.transaction_split_id')
                    ->orWhereNotExists(function (QueryBuilder $sub) use ($connection): void {
                        $sub->select($connection->raw(1))
                            ->from('tax_transaction_tags AS tag2')
                            ->whereColumn('tag2.transaction_id', 'tag.transaction_id')
                            ->whereColumn('tag2.user_id', 'tag.user_id')
                            ->whereNotNull('tag2.transaction_split_id');
                    });
            })
            ->orderBy('cat.sort_order')
            ->orderBy('t.booked_at')
            ->select([
                'tag.id AS tag_id',
                'tag.transaction_split_id',
                't.id AS transaction_id',
                't.booked_at',
                $connection->raw('COALESCE(ts.settled_amount_minor, t.settled_amount_minor) AS settled_amount_minor'),
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
                'cp.iban AS counterparty_iban',
            ])
            ->get();

        if ($rawRows->isEmpty()) {
            return new TaxYearData(
                year: $year,
                deductionsTotalMinor: 0,
                incomeTotalMinor: 0,
                itemCount: 0,
                categories: [],
            );
        }

        // Group rows by category id; a null id lands in the trailing
        // "no category" group built separately below.
        /** @var array<string, array{id: int, name: string|null, shortName: string|null, subtotalMinor: int, rows: list<array<string,mixed>>}> $groups */
        $groups = [];
        /** @var array{id: null, name: null, shortName: null, subtotalMinor: int, rows: list<array<string,mixed>>}|null $noCategory */
        $noCategory = null;

        $deductionsTotal = 0;
        $incomeTotal = 0;

        foreach ($rawRows as $row) {
            $minor = self::toInt($row->settled_amount_minor);
            $transactionType = self::toStr($row->transaction_type);
            $isIncome = $transactionType === 'income';

            // abs() converts the signed stored minor amount to the
            // unsigned total the UI expects.
            if ($isIncome) {
                $incomeTotal += abs($minor);
            } else {
                $deductionsTotal += abs($minor);
            }

            // Read-side decrypt — the tax cockpit / CSV / PDF export reads
            // description/counterparty display name/iban/note, all
            // ciphertext at rest once encryption is enabled; a pass-through
            // no-op otherwise.
            $rowData = [
                'transactionId' => self::toInt($row->transaction_id),
                'transactionSplitId' => $row->transaction_split_id !== null ? self::toInt($row->transaction_split_id) : null,
                'bookedAt' => self::toStrOrNull($row->booked_at),
                'accountName' => self::toStrOrNull($row->account_name),
                'counterpartyName' => $this->decryptOrNull('counterparties', 'display_name', $row->counterparty_name, $userId),
                'counterpartyIban' => $this->decryptOrNull('counterparties', 'iban', $row->counterparty_iban, $userId),
                'description' => $this->decryptOrNull('transactions', 'description', $row->description, $userId),
                'note' => $this->decryptOrNull('tax_transaction_tags', 'note', $row->note, $userId),
                'settledAmountMinor' => $minor,
                'settledCurrency' => self::toStr($row->settled_currency),
                'amountMinor' => self::toInt($row->amount_minor),
                'currency' => self::toStr($row->currency),
                'transactionType' => $transactionType,
                'categoryId' => $row->category_id !== null ? self::toInt($row->category_id) : null,
                'categoryName' => self::toStrOrNull($row->category_name),
                'categoryShortName' => self::toStrOrNull($row->category_short_name),
                'taxYearOverride' => $row->tax_year_override !== null ? self::toInt($row->tax_year_override) : null,
                'sourceFormat' => self::toStr($row->source_format),
                'importRunId' => self::toInt($row->import_run_id),
                'fingerprint' => self::toStr($row->fingerprint),
            ];

            if ($row->category_id === null) {
                // Accumulate into the trailing no-category group, created
                // lazily on first encounter.
                if ($noCategory === null) {
                    $noCategory = [
                        'id' => null,
                        'name' => null,
                        'shortName' => null,
                        'subtotalMinor' => 0,
                        'rows' => [],
                    ];
                }
                $noCategory['subtotalMinor'] += abs($minor);
                $noCategory['rows'][] = $rowData;
            } else {
                $catKey = (string) self::toInt($row->category_id);
                if (! array_key_exists($catKey, $groups)) {
                    $groups[$catKey] = [
                        'id' => self::toInt($row->category_id),
                        'name' => self::toStrOrNull($row->category_name),
                        'shortName' => self::toStrOrNull($row->category_short_name),
                        'subtotalMinor' => 0,
                        'rows' => [],
                    ];
                }
                $groups[$catKey]['subtotalMinor'] += abs($minor);
                $groups[$catKey]['rows'][] = $rowData;
            }
        }

        $categories = array_values($groups);
        if ($noCategory !== null) {
            $categories[] = $noCategory;
        }

        return new TaxYearData(
            year: $year,
            deductionsTotalMinor: $deductionsTotal,
            incomeTotalMinor: $incomeTotal,
            itemCount: $rawRows->count(),
            categories: $categories,
        );
    }

    /**
     * @return array<int>
     */
    public function availableYears(int $userId): array
    {
        $connection = $this->db->connection();

        $rows = $connection
            ->table('tax_transaction_tags AS tag')
            ->join('transactions AS t', 't.id', '=', 'tag.transaction_id')
            ->where('tag.user_id', $userId)
            ->selectRaw(
                'DISTINCT COALESCE(tag.tax_year_override, CAST(strftime(\'%Y\', t.booked_at) AS INTEGER)) AS effective_year',
            )
            ->orderByRaw('effective_year DESC')
            ->get();

        $years = [];
        foreach ($rows as $row) {
            $years[] = self::toInt($row->effective_year);
        }

        return $years;
    }

    // Returns null when the stored value isn't a non-empty string; never
    // throws — a pass-through no-op when encryption is not enabled.
    private function decryptOrNull(string $table, string $field, mixed $value, int $userId): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->codec->decryptValue($table, $field, $value, $userId, ($this->session)())['value'];
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function toStrOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
    }
}
