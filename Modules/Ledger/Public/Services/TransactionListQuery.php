<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Dto\TransactionListPage;
use Modules\Ledger\Public\Dto\TransactionRowDto;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * Paginated read access to the transactions table for the dashboard's
 * "recent transactions" panel and the `/transactions` list page.
 *
 * Two entry points:
 *
 *  - `recent($user, daysBack=90)` — the UI-04 default for `/transactions`.
 *    Filters to `posted_at >= today - daysBack` so the list shows the
 *    rolling recent window. The Clock dependency means the cutoff is
 *    deterministic under `CarbonImmutable::setTestNow()`.
 *  - `fullHistory($user)` — returns every row regardless of date. The
 *    `/transactions` page toggles to this when the user clicks
 *    "Show full history".
 *
 * Cursor pagination is implemented by selecting `limit + 1` rows and
 * trimming the last one off; `nextCursorId` then carries the smallest
 * id in the trimmed page so the next page applies `WHERE id < $cursor`.
 *
 * Uses the `DatabaseManager` query builder directly (rather than the
 * Eloquent Builder) to stay clean under `phpstan-strict-rules`'
 * `staticMethod.dynamicCall` rule and to keep the SELECT minimal — the
 * dashboard panel needs only the six columns rendered in the row DTO.
 */
final class TransactionListQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
    ) {}

    public function recent(User $user, int $daysBack = 90, ?int $cursorId = null, int $limit = 50): TransactionListPage
    {
        $cutoff = $this->clock->now()->subDays($daysBack);

        $query = $this->baseQuery($user)
            ->where('transactions.posted_at', '>=', $cutoff->toDateString())
            ->limit($limit + 1);

        if ($cursorId !== null) {
            $query->where('transactions.id', '<', $cursorId);
        }

        return $this->buildPage($query, $limit);
    }

    public function fullHistory(User $user, ?int $cursorId = null, int $limit = 50): TransactionListPage
    {
        $query = $this->baseQuery($user)->limit($limit + 1);

        if ($cursorId !== null) {
            $query->where('transactions.id', '<', $cursorId);
        }

        return $this->buildPage($query, $limit);
    }

    private function baseQuery(User $user): Builder
    {
        return $this->db->connection()
            ->table('transactions')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->where('transactions.user_id', $user->id)
            ->orderByDesc('transactions.posted_at')
            ->orderByDesc('transactions.id')
            ->select([
                'transactions.id',
                'transactions.booked_at',
                'transactions.counterparty_name',
                'transactions.category_id',
                'transactions.amount_minor',
                'transactions.currency',
                'categories.name as category_name',
            ]);
    }

    private function buildPage(Builder $query, int $limit): TransactionListPage
    {
        $rows = $query->get();
        $hasMore = $rows->count() > $limit;

        $sliced = $rows->take($limit)->values();

        $dtos = [];
        $lastId = null;
        foreach ($sliced as $row) {
            $dtos[] = $this->mapRow($row);
            $lastId = self::toInt($row->id);
        }

        return new TransactionListPage(
            rows: $dtos,
            hasMore: $hasMore,
            nextCursorId: $hasMore ? $lastId : null,
        );
    }

    private function mapRow(stdClass $row): TransactionRowDto
    {
        $bookedAt = CarbonImmutable::parse(self::toString($row->booked_at));
        $categoryId = $row->category_id === null ? null : self::toInt($row->category_id);
        $categoryName = $row->category_name === null ? null : self::toString($row->category_name);
        $counterpartyName = $row->counterparty_name === null ? null : self::toString($row->counterparty_name);

        return new TransactionRowDto(
            id: self::toInt($row->id),
            bookedAt: $bookedAt->format('d-m-Y'),
            counterpartyName: $counterpartyName,
            categoryId: $categoryId,
            categoryName: $categoryName,
            amount: Money::ofMinor(self::toInt($row->amount_minor), self::toString($row->currency)),
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
