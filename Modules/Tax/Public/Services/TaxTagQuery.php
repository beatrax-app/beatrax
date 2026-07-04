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
     * WHOLE-TRANSACTION ONLY (Phase 13.3 Finding A): filters to
     * `transaction_split_id IS NULL`. Leg rows carry the PARENT
     * transaction_id (Phase 13.1 D-06a), so without this filter a tag on
     * ONE leg of a split would light up the whole-transaction badge for
     * the parent row — and the whole-tx untag path (which also scopes to
     * `whereNull('transaction_split_id')`, see UntagTransaction) would then
     * match zero rows on click, a silent no-op. Callers that need
     * leg-aware state use `forTransactionIdsWithLegs()` instead.
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
            ->whereNull('tag.transaction_split_id')
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
     * Batch-load per-LEG tax tag state for the given transaction ids
     * (Phase 13.1 D-06a).
     *
     * Returns a map keyed by "{transactionId}:{transactionSplitId}" for a
     * leg-scoped tag, or "{transactionId}:whole" for a whole-transaction
     * tag, so the split editor can render each leg's tax badge
     * independently by (transactionId, transactionSplitId). Only TAGGED
     * pairs appear — callers treat absence as "untagged", mirroring
     * forTransactionIds()'s existing absence convention.
     *
     * Single `whereIn` query regardless of batch size — N+1 prevention,
     * matching forTransactionIds()'s D-03 Pitfall 1 guard. This method does
     * NOT replace forTransactionIds() — unsplit callers keep using that
     * whole-transaction-only method unchanged.
     *
     * @param  array<int>  $transactionIds
     * @return array<string, TaxTagData>
     */
    public function forTransactionIdsWithLegs(int $userId, array $transactionIds): array
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
                'tag.transaction_split_id',
                'tag.deduction_category_id',
                'cat.short_name AS category_short_name',
                'tag.note',
                'tag.tax_year_override',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $txId = self::toInt($row->transaction_id);
            $splitId = $row->transaction_split_id !== null ? self::toInt($row->transaction_split_id) : null;
            $key = $txId.':'.($splitId !== null ? (string) $splitId : 'whole');

            $map[$key] = new TaxTagData(
                transactionId: $txId,
                deductionCategoryId: $row->deduction_category_id !== null ? self::toInt($row->deduction_category_id) : null,
                deductionCategoryShortName: self::toStrOrNull($row->category_short_name),
                note: self::toStrOrNull($row->note),
                taxYearOverride: $row->tax_year_override !== null ? self::toInt($row->tax_year_override) : null,
                transactionSplitId: $splitId,
            );
        }

        return $map;
    }

    /**
     * Booked (calendar) year of a transaction, or null when the transaction
     * does not exist or belongs to another user.
     *
     * Used by the tag picker to decide whether to render the D-10
     * year-assignment row (booked year ≠ current tax year).
     */
    public function bookedYearFor(int $userId, int $transactionId): ?int
    {
        $bookedAt = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('booked_at');

        if (! is_string($bookedAt) || strlen($bookedAt) < 4) {
            return null;
        }

        $year = (int) substr($bookedAt, 0, 4);

        return $year > 0 ? $year : null;
    }

    /**
     * Compact year summary (count + total) for the sidebar / dashboard.
     *
     * Uses COALESCE to respect tax_year_override for each tagged row.
     *
     * totalMinor is the DEDUCTIONS total only (non-income rows), matching
     * the /tax cockpit's headline "Total deductions" KPI — folding income
     * and deductions into one absolute sum produced a figure that matched
     * neither number on /tax (IN-08). count still covers ALL tagged items.
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
            ->selectRaw("COUNT(*) AS cnt, SUM(CASE WHEN t.type = 'income' THEN 0 ELSE ABS(t.settled_amount_minor) END) AS total_minor")
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

    /**
     * Fetch the ids of untagged transactions for a given counterparty + tax year.
     *
     * Used by HandlesTaxTagging::applyBatchTag to tag all siblings in one pass.
     * Scoped to $userId — returns only rows owned by this user (T-07-20).
     *
     * @return list<int>
     */
    public function untaggedIdsForCounterparty(int $userId, int $counterpartyId, int $taxYear): array
    {
        $rows = $this->db->connection()
            ->table('transactions AS t')
            ->leftJoin('tax_transaction_tags AS tag', static function (JoinClause $join) use ($userId): void {
                $join->on('tag.transaction_id', '=', 't.id')
                    ->where('tag.user_id', '=', $userId);
            })
            ->whereNull('tag.id')
            ->where('t.user_id', $userId)
            ->where('t.counterparty_id', $counterpartyId)
            ->whereRaw(
                'CAST(strftime(\'%Y\', t.booked_at) AS INTEGER) = ?',
                [$taxYear],
            )
            ->pluck('t.id');

        /** @var list<int> $result */
        $result = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows->all()));

        return $result;
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
