<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Tax\Public\Dto\BatchTagSuggestion;
use Modules\Tax\Public\Dto\TaxTagData;
use Modules\Tax\Public\Dto\TaxYearSummary;

/**
 * Lightweight tagged-state lookup service for badge surfaces, dashboard, and nav.
 *
 * This is the PUBLIC query service: its methods are consumed cross-module by
 * badge partials, the dashboard tile, and the sidebar nav count.
 *
 * Security:
 *  - Every query scopes to `user_id = $userId` before returning data (T-07-06).
 *  - `forTransactionIds` uses a single `whereIn` query for the full batch
 *    so callers never issue N+1 queries (D-03 Pitfall 1).
 */
final class TaxTagQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Return a map of transactionId → TaxTagData for the given ids.
     *
     * Only TAGGED ids appear in the result. Callers treat absence as "untagged".
     * Single `whereIn` query regardless of batch size — N+1 prevention.
     *
     * @param  array<int>  $transactionIds
     * @return array<int, TaxTagData>
     */
    public function forTransactionIds(int $userId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table('tax_transaction_tags AS tag')
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->where('tag.user_id', $userId)
            ->whereIn('tag.transaction_id', $transactionIds)
            ->get([
                'tag.transaction_id',
                'tag.deduction_category_id',
                'cat.short_name AS category_short_name',
                'tag.note',
                'tag.tax_year_override',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $txId = self::toInt($row->transaction_id);
            $map[$txId] = new TaxTagData(
                transactionId: $txId,
                deductionCategoryId: $row->deduction_category_id !== null ? self::toInt($row->deduction_category_id) : null,
                deductionCategoryShortName: self::toStrOrNull($row->category_short_name),
                note: self::toStrOrNull($row->note),
                taxYearOverride: $row->tax_year_override !== null ? self::toInt($row->tax_year_override) : null,
            );
        }

        return $map;
    }

    /**
     * Compact year summary (count + total) for the sidebar / dashboard.
     *
     * Uses COALESCE to respect tax_year_override for each tagged row.
     */
    public function summaryForUser(int $userId, int $year): TaxYearSummary
    {
        $row = $this->db->connection()
            ->table('tax_transaction_tags AS tag')
            ->join('transactions AS t', 't.id', '=', 'tag.transaction_id')
            ->where('tag.user_id', $userId)
            ->whereRaw(
                'COALESCE(tag.tax_year_override, CAST(strftime(\'%Y\', t.booked_at) AS INTEGER)) = ?',
                [$year],
            )
            ->selectRaw('COUNT(*) AS cnt, SUM(ABS(t.settled_amount_minor)) AS total_minor')
            ->first();

        return new TaxYearSummary(
            year: $year,
            totalMinor: $row !== null ? self::toInt($row->total_minor) : 0,
            count: $row !== null ? self::toInt($row->cnt) : 0,
        );
    }

    /**
     * Count of OTHER untagged transactions for the same counterparty in the tax year.
     *
     * Used by TaxPage after a tag action to show "Also tag N more from [Gym] this year?".
     * Returns BatchTagSuggestion with untaggedCount=0 when the transaction has no counterparty.
     *
     * The count EXCLUDES the just-tagged transaction itself (D-03).
     */
    public function untaggedCountForCounterparty(int $userId, int $transactionId, int $taxYear): BatchTagSuggestion
    {
        $connection = $this->db->connection();

        // Resolve counterparty_id of the just-tagged transaction (user-scoped).
        $tx = $connection
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->first(['counterparty_id']);

        if ($tx === null || $tx->counterparty_id === null) {
            return new BatchTagSuggestion(
                counterpartyId: 0,
                counterpartyName: '',
                untaggedCount: 0,
            );
        }

        $cpId = self::toInt($tx->counterparty_id);

        // Resolve counterparty name.
        $cpRow = $connection
            ->table('counterparties')
            ->where('id', $cpId)
            ->first(['display_name']);

        $cpName = $cpRow !== null ? self::toStr($cpRow->display_name) : '';

        // Count OTHER untagged transactions for this counterparty in the effective tax year.
        $untaggedCount = $connection
            ->table('transactions AS t')
            ->leftJoin('tax_transaction_tags AS tag', static function (JoinClause $join) use ($userId): void {
                $join->on('tag.transaction_id', '=', 't.id')
                    ->where('tag.user_id', '=', $userId);
            })
            ->whereNull('tag.id')
            ->where('t.user_id', $userId)
            ->where('t.counterparty_id', $cpId)
            ->where('t.id', '!=', $transactionId)
            ->whereRaw(
                'CAST(strftime(\'%Y\', t.booked_at) AS INTEGER) = ?',
                [$taxYear],
            )
            ->count();

        return new BatchTagSuggestion(
            counterpartyId: $cpId,
            counterpartyName: $cpName,
            untaggedCount: $untaggedCount,
        );
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
