<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Tax\Public\Dto\TaxYearData;

/**
 * Query service: grouped per-year tax data with COALESCE year-override resolution.
 *
 * All reads are scoped to a single user via `where('tag.user_id', $userId)` as
 * the first filter on every query — structural ownership guard (T-07-05).
 *
 * Year resolution uses COALESCE(tag.tax_year_override, CAST(strftime('%Y', t.booked_at)
 * AS INTEGER)) so that a manually-overridden tax year always takes precedence
 * over the booked date (D-10, T-07-07).
 *
 * Amounts are always summed from settled_amount_minor (settled EUR). No
 * floating-point arithmetic is performed on money values in this class.
 *
 * Leg-aware (Phase 13.1 D-06a): a tag scoped to one leg of a split
 * transaction (`tag.transaction_split_id` NOT NULL) reports the LEG's own
 * `settled_amount_minor` via `LEFT JOIN transaction_splits AS ts ON ts.id =
 * tag.transaction_split_id` + `COALESCE(ts.settled_amount_minor,
 * t.settled_amount_minor)` — never the whole parent's amount. This is a
 * tax-correctness requirement: a €80 transaction split €60 Groceries
 * (tagged deductible) / €20 Household (not tagged) must export €60, not
 * €80 and not €0.
 *
 * SUPERSESSION POLICY (locked default): once a transaction has ANY
 * leg-scoped tag, its whole-transaction tag row (transaction_split_id IS
 * NULL) is excluded from these results — it is not deleted anywhere, just
 * no longer surfaced/exported, so a stale pre-split tag can never
 * double-report alongside its legs.
 *
 * The category name shown for a leg-scoped tag resolves from
 * `tag.deduction_category_id` (the TAX deduction category) — never from the
 * leg's own `category_id` (the SPEND category on `transaction_splits`).
 * These are distinct concepts and must not be conflated.
 */
final class TaxYearQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Return grouped tax-year data for a user and effective tax year.
     *
     * Rows are grouped by deduction_category_id (NULL rows land in a
     * trailing "no category" group). The `$categories` array in the
     * returned DTO has the shape:
     *   [ 'id' => int|null, 'name' => string|null, 'shortName' => string|null,
     *     'subtotalMinor' => int, 'rows' => list<array<string,mixed>> ]
     *
     * Income vs deduction split: `type = 'income'` counts toward
     * incomeTotalMinor; all other types toward deductionsTotalMinor.
     * Amounts are stored signed in the DB (expenses negative); this method
     * uses abs() to produce unsigned subtotals and totals that match the
     * UI's "you spent X" language.
     */
    public function forUser(int $userId, int $year): TaxYearData
    {
        $connection = $this->db->connection();

        $rawRows = $connection
            ->table('tax_transaction_tags AS tag')
            ->join('transactions AS t', 't.id', '=', 'tag.transaction_id')
            // Leg-aware amount resolution (D-06a): a leg-scoped tag joins to
            // its own transaction_splits row; a whole-tx tag's ts.* columns
            // are NULL and the COALESCE below falls back to the parent.
            ->leftJoin('transaction_splits AS ts', 'ts.id', '=', 'tag.transaction_split_id')
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->leftJoin('accounts AS a', 'a.id', '=', 't.account_id')
            ->leftJoin('counterparties AS cp', 'cp.id', '=', 't.counterparty_id')
            // T-07-05: user scope is the first filter, never omitted.
            ->where('tag.user_id', $userId)
            // D-10 / T-07-07: effective year via COALESCE override.
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

        // Group rows by category id (null → trailing "no category" group).
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

            // Totals — use abs() to convert signed minor amounts to unsigned.
            if ($isIncome) {
                $incomeTotal += abs($minor);
            } else {
                $deductionsTotal += abs($minor);
            }

            $rowData = [
                'transactionId' => self::toInt($row->transaction_id),
                'transactionSplitId' => $row->transaction_split_id !== null ? self::toInt($row->transaction_split_id) : null,
                'bookedAt' => self::toStrOrNull($row->booked_at),
                'accountName' => self::toStrOrNull($row->account_name),
                'counterpartyName' => self::toStrOrNull($row->counterparty_name),
                'counterpartyIban' => self::toStrOrNull($row->counterparty_iban),
                'description' => self::toStrOrNull($row->description),
                'note' => self::toStrOrNull($row->note),
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
                // Accumulate into the trailing no-category group.
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

        // Build ordered categories list: named groups first, then "no category" trailing.
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
     * Return distinct effective tax years for a user in descending order.
     *
     * Effective year = COALESCE(tax_year_override, CAST(strftime('%Y', booked_at) AS INTEGER)).
     *
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
