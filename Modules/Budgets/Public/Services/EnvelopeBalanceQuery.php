<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Budgets\Public\Dto\EnvelopeMoveRow;
use Modules\Ledger\Public\Dto\Period;

/**
 * Read model over the append-only `envelope_moves` ledger (D-02/D-19),
 * mirroring `Modules\Pots\Public\Services\PotBalanceQuery`'s SUM-at-read
 * discipline: there is no stored balance column anywhere — a category's
 * `net_moved` for a period is always `SUM(amount_minor)` over its own rows
 * for that period, derived fresh on every read.
 *
 * Every method takes an explicit `$userId`/`$user` and filters `WHERE
 * user_id = ?` before anything else — no reliance on a global UserScope for
 * this cross-module Public service (T-13.2-03-01, mirrors PotBalanceQuery /
 * BudgetProgressQuery precedent).
 */
final class EnvelopeBalanceQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Returns the signed `SUM(amount_minor)` for one envelope's own
     * `envelope_moves` rows in one period — CarryoverQuery's `netMoved`
     * input (D-05: `available = assigned + carried_in + net_moved - spent`).
     * Returns 0 when no move rows exist for the (user, category, period).
     */
    public function netMoved(int $userId, int $categoryId, Period $period): int
    {
        $value = $this->db->connection()
            ->table('envelope_moves')
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->where('period_start', $period->start->toDateString())
            ->sum('amount_minor');

        return self::toInt($value);
    }

    /**
     * Returns up to `$limit` of this envelope's own moves for one period,
     * newest-first, feeding the per-envelope recent-moves + undo UI (D-19).
     *
     * `direction` is derived from `kind` ('move_in' => 'in', 'move_out' =>
     * 'out'). `counterpartCategoryName` is resolved via a permitted category
     * read (`user_id IS NULL OR user_id = ?`, mirroring `BudgetProgressQuery`
     * /`PotBalanceQuery`'s cross-user category-visibility guard) — every
     * envelope move has a real counterpart category (D-02, NOT NULL +
     * cascadeOnDelete), so this is never null for a live row.
     *
     * @return list<EnvelopeMoveRow>
     */
    public function recentMovesFor(int $userId, int $categoryId, Period $period, int $limit = 10): array
    {
        $rows = $this->db->connection()
            ->table('envelope_moves as m')
            ->join('categories as c', 'c.id', '=', 'm.counterpart_category_id')
            ->where('m.user_id', $userId)
            ->where('m.category_id', $categoryId)
            ->where('m.period_start', $period->start->toDateString())
            ->where(static function (QueryBuilder $query) use ($userId): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->limit($limit)
            ->get([
                'm.id',
                'm.kind',
                'm.amount_minor',
                'm.counterpart_category_id',
                'c.name as counterpart_category_name',
                'm.memo',
                'm.created_at',
            ]);

        $result = [];
        foreach ($rows as $row) {
            $direction = self::toStr($row->kind) === 'move_in' ? 'in' : 'out';

            $createdAtRaw = $row->created_at ?? null;
            $createdAt = '';
            if ($createdAtRaw !== null && $createdAtRaw !== '') {
                try {
                    $createdAt = CarbonImmutable::parse(self::toStr($createdAtRaw))->format('Y-m-d H:i');
                } catch (\Throwable) {
                    $createdAt = '';
                }
            }

            $result[] = new EnvelopeMoveRow(
                id: self::toInt($row->id),
                direction: $direction,
                amountMinor: self::toInt($row->amount_minor),
                counterpartCategoryId: self::toInt($row->counterpart_category_id),
                counterpartCategoryName: self::toStr($row->counterpart_category_name),
                memo: is_string($row->memo) ? $row->memo : null,
                createdAt: $createdAt,
            );
        }

        return $result;
    }

    /**
     * Batched variant of `recentMovesFor()` (IN-01): loads up to `$limit`
     * recent moves for MANY categories in ONE query, bucketed by category id
     * in PHP — the same shape CarryoverQuery's batchAssignments/batchMoves use.
     * Avoids the N+1 of calling `recentMovesFor()` once per envelope on every
     * render.
     *
     * Every returned category id (including one with no moves) maps to a
     * possibly-empty list, so callers can index the result directly. Per-
     * category ordering and `$limit` semantics mirror `recentMovesFor()`
     * exactly (newest-first by created_at then id, capped in PHP).
     *
     * @param  list<int>  $categoryIds
     * @return array<int, list<EnvelopeMoveRow>> category_id => recent moves
     */
    public function recentMovesForCategories(int $userId, array $categoryIds, Period $period, int $limit = 10): array
    {
        $buckets = [];
        foreach ($categoryIds as $categoryId) {
            $buckets[$categoryId] = [];
        }

        if ($categoryIds === []) {
            return $buckets;
        }

        $rows = $this->db->connection()
            ->table('envelope_moves as m')
            ->join('categories as c', 'c.id', '=', 'm.counterpart_category_id')
            ->where('m.user_id', $userId)
            ->whereIn('m.category_id', $categoryIds)
            ->where('m.period_start', $period->start->toDateString())
            ->where(static function (QueryBuilder $query) use ($userId): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->get([
                'm.id',
                'm.category_id',
                'm.kind',
                'm.amount_minor',
                'm.counterpart_category_id',
                'c.name as counterpart_category_name',
                'm.memo',
                'm.created_at',
            ]);

        foreach ($rows as $row) {
            $categoryId = self::toInt($row->category_id);
            if (! array_key_exists($categoryId, $buckets) || count($buckets[$categoryId]) >= $limit) {
                continue;
            }

            $direction = self::toStr($row->kind) === 'move_in' ? 'in' : 'out';

            $createdAtRaw = $row->created_at ?? null;
            $createdAt = '';
            if ($createdAtRaw !== null && $createdAtRaw !== '') {
                try {
                    $createdAt = CarbonImmutable::parse(self::toStr($createdAtRaw))->format('Y-m-d H:i');
                } catch (\Throwable) {
                    $createdAt = '';
                }
            }

            $buckets[$categoryId][] = new EnvelopeMoveRow(
                id: self::toInt($row->id),
                direction: $direction,
                amountMinor: self::toInt($row->amount_minor),
                counterpartCategoryId: self::toInt($row->counterpart_category_id),
                counterpartCategoryName: self::toStr($row->counterpart_category_name),
                memo: is_string($row->memo) ? $row->memo : null,
                createdAt: $createdAt,
            );
        }

        return $buckets;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
