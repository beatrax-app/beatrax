<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Budgets\Public\Dto\EnvelopeMoveRow;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Dto\Period;

// There is no stored balance column anywhere: a category's net_moved is
// always SUM(amount_minor) over its own rows, derived fresh on every read.
final class EnvelopeBalanceQuery
{
    use CoercesScalars;

    public function __construct(private readonly DatabaseManager $db) {}

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
            $result[] = $this->mapMoveRow($row);
        }

        return $result;
    }

    // Batched variant of recentMovesFor(): one query for many categories, so
    // a render does not go N+1 across the envelopes on the page.
    /**
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

            $buckets[$categoryId][] = $this->mapMoveRow($row);
        }

        return $buckets;
    }

    private function mapMoveRow(\stdClass $row): EnvelopeMoveRow
    {
        return new EnvelopeMoveRow(
            id: self::toInt($row->id),
            direction: self::toString($row->kind) === 'move_in' ? 'in' : 'out',
            amountMinor: self::toInt($row->amount_minor),
            counterpartCategoryId: self::toInt($row->counterpart_category_id),
            counterpartCategoryName: self::toString($row->counterpart_category_name),
            memo: is_string($row->memo) ? $row->memo : null,
            createdAt: $this->formatCreatedAt($row->created_at ?? null),
        );
    }

    private function formatCreatedAt(mixed $createdAtRaw): string
    {
        return SafeDate::parseOrNull(self::toString($createdAtRaw))?->format('Y-m-d H:i') ?? '';
    }
}
