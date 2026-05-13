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
 *  - `recent($user, daysBack=90)` — the default for `/transactions`. Filters
 *    to `posted_at >= today - daysBack` so the list shows the rolling recent
 *    window. The Clock dependency means the cutoff is deterministic under
 *    `CarbonImmutable::setTestNow()`.
 *  - `fullHistory($user)` — returns every row regardless of date. The
 *    `/transactions` page toggles to this when the user clicks
 *    "Show full history".
 *
 * Both entry points accept an optional `$currency` filter that, when
 * supplied, restricts the result to rows whose `settled_currency` matches
 * AND projects the settled pair (`settled_amount_minor` /
 * `settled_currency`) as the rendered amount. This keeps the dashboard
 * coherent: a EUR view shows every row's amount in EUR, even when the
 * native pair is USD (e.g. a Google Play USD charge settled to EUR by
 * the card issuer). The `/transactions` page leaves `$currency` null,
 * which keeps the full multi-currency list visible and renders each
 * row's native pair.
 *
 * Cursor pagination is implemented by selecting `limit + 1` rows and
 * trimming the last one off. The cursor is a `(posted_at, id)` pair — the
 * next page applies `WHERE (posted_at, id) < (?, ?)` using SQLite's row-value
 * compare. The pair (rather than `id` alone) is required because rows
 * inserted in non-chronological order can share a `posted_at` value, and a
 * single-column id cursor would silently drop them.
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

    public function recent(
        User $user,
        int $daysBack = 90,
        ?int $cursorId = null,
        int $limit = 50,
        ?string $cursorPostedAt = null,
        ?string $currency = null,
    ): TransactionListPage {
        $cutoff = $this->clock->now()->subDays($daysBack);

        $query = $this->baseQuery($user, $currency)
            ->where('transactions.posted_at', '>=', $cutoff->toDateString())
            ->limit($limit + 1);

        $this->applyCursor($query, $cursorPostedAt, $cursorId);

        return $this->buildPage($query, $limit);
    }

    public function fullHistory(
        User $user,
        ?int $cursorId = null,
        int $limit = 50,
        ?string $cursorPostedAt = null,
        ?string $currency = null,
    ): TransactionListPage {
        $query = $this->baseQuery($user, $currency)->limit($limit + 1);

        $this->applyCursor($query, $cursorPostedAt, $cursorId);

        return $this->buildPage($query, $limit);
    }

    private function baseQuery(User $user, ?string $currency = null): Builder
    {
        // When a currency filter is supplied, project the settled pair as
        // `display_minor` / `display_currency` so the rendered amount
        // matches the filter. Otherwise project the native pair so the
        // full-history view shows every row in its original currency.
        $amountMinorColumn = $currency === null
            ? 'transactions.amount_minor as display_minor'
            : 'transactions.settled_amount_minor as display_minor';
        $currencyColumn = $currency === null
            ? 'transactions.currency as display_currency'
            : 'transactions.settled_currency as display_currency';

        $query = $this->db->connection()
            ->table('transactions')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->where('transactions.user_id', $user->id)
            ->orderByDesc('transactions.posted_at')
            ->orderByDesc('transactions.id')
            ->select([
                'transactions.id',
                'transactions.posted_at',
                'transactions.booked_at',
                'transactions.counterparty_name',
                'transactions.category_id',
                $amountMinorColumn,
                $currencyColumn,
                'categories.name as category_name',
            ]);

        if ($currency !== null) {
            $query->where('transactions.settled_currency', $currency);
        }

        return $query;
    }

    private function applyCursor(Builder $query, ?string $cursorPostedAt, ?int $cursorId): void
    {
        if ($cursorId === null) {
            return;
        }

        if ($cursorPostedAt === null) {
            // Legacy single-id cursor: behaves as the old id-only filter so an
            // upgrade path remains backwards-compatible. Callers should supply
            // the pair to get correct ordering across posted_at ties.
            $query->where('transactions.id', '<', $cursorId);

            return;
        }

        // Row-value tuple compare. SQLite ships this since 3.15; the project
        // pins to a 3.45+ runtime so the syntax is always available.
        $query->whereRaw(
            '(transactions.posted_at, transactions.id) < (?, ?)',
            [$cursorPostedAt, $cursorId],
        );
    }

    private function buildPage(Builder $query, int $limit): TransactionListPage
    {
        $rows = $query->get();
        $hasMore = $rows->count() > $limit;

        $sliced = $rows->take($limit)->values();

        $dtos = [];
        $lastId = null;
        $lastPostedAt = null;
        foreach ($sliced as $row) {
            $dtos[] = $this->mapRow($row);
            $lastId = self::toInt($row->id);
            $lastPostedAt = self::toString($row->posted_at);
        }

        return new TransactionListPage(
            rows: $dtos,
            hasMore: $hasMore,
            nextCursorId: $hasMore ? $lastId : null,
            nextCursorPostedAt: $hasMore ? $lastPostedAt : null,
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
            amount: Money::ofMinor(self::toInt($row->display_minor), self::toString($row->display_currency)),
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
