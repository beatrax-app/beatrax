<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\Fmt;
use Modules\Ledger\Public\Dto\TransactionListPage;
use Modules\Ledger\Public\Dto\TransactionRowDto;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final class TransactionListQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
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

        return $this->buildPage($query, $limit, $user->id);
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

        return $this->buildPage($query, $limit, $user->id);
    }

    private function baseQuery(User $user, ?string $currency = null): Builder
    {
        // See the linked architecture page for the currency-projection
        // and secondary-pair contract this method implements.
        $amountMinorColumn = $currency === null
            ? 'transactions.amount_minor as display_minor'
            : 'transactions.settled_amount_minor as display_minor';
        $currencyColumn = $currency === null
            ? 'transactions.currency as display_currency'
            : 'transactions.settled_currency as display_currency';

        $select = [
            'transactions.id',
            'transactions.posted_at',
            'transactions.booked_at',
            'transactions.counterparty_name',
            'transactions.category_id',
            $amountMinorColumn,
            $currencyColumn,
            'categories.name as category_name',
            'counterparties.slug as counterparty_slug',
        ];

        if ($currency === null) {
            $select[] = 'transactions.settled_amount_minor as secondary_minor';
            $select[] = 'transactions.settled_currency as secondary_currency';
        }

        // Left-join counterparties so each row carries its resolved
        // slug in a single query (no N+1). Rows without a resolved
        // counterparty yield NULL, and the Blade falls back to plain text.
        $query = $this->db->connection()
            ->table('transactions')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.user_id', $user->id)
            ->orderByDesc('transactions.posted_at')
            ->orderByDesc('transactions.id')
            ->select($select);

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
            // Legacy single-id cursor, kept for backwards compatibility;
            // callers should supply the pair for correct posted_at-tie ordering.
            $query->where('transactions.id', '<', $cursorId);

            return;
        }

        $query->whereRaw(
            '(transactions.posted_at, transactions.id) < (?, ?)',
            [$cursorPostedAt, $cursorId],
        );
    }

    private function buildPage(Builder $query, int $limit, int $userId): TransactionListPage
    {
        $rows = $query->get();
        $hasMore = $rows->count() > $limit;

        $sliced = $rows->take($limit)->values();

        $dtos = [];
        $lastId = null;
        $lastPostedAt = null;
        foreach ($sliced as $row) {
            $dtos[] = $this->mapRow($row, $userId);
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

    private function mapRow(stdClass $row, int $userId): TransactionRowDto
    {
        $bookedAt = CarbonImmutable::parse(self::toString($row->booked_at));
        $categoryId = $row->category_id === null ? null : self::toInt($row->category_id);
        $categoryName = $row->category_name === null ? null : self::toString($row->category_name);
        // Read-side decrypt — pass-through no-op when encryption is not
        // enabled for this user.
        $counterpartyName = $row->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toString($row->counterparty_name), $userId, ($this->session)())['value'];
        $counterpartySlug = property_exists($row, 'counterparty_slug') && $row->counterparty_slug !== null
            ? self::toString($row->counterparty_slug)
            : null;
        // Empty (not null) slug is treated as "no slug" — see the
        // linked architecture page for why.
        if ($counterpartySlug === '') {
            $counterpartySlug = null;
        }

        $displayCurrency = self::toString($row->display_currency);
        $secondaryAmount = null;
        if (property_exists($row, 'secondary_currency') && property_exists($row, 'secondary_minor')) {
            $secondaryCurrency = self::toString($row->secondary_currency);
            // Only true FX rows get a secondary line; EUR-native rows
            // have mirrored pairs and stay on a single line.
            if ($secondaryCurrency !== '' && $secondaryCurrency !== $displayCurrency) {
                $secondaryAmount = Money::ofMinor(self::toInt($row->secondary_minor), $secondaryCurrency);
            }
        }

        return new TransactionRowDto(
            id: self::toInt($row->id),
            bookedAt: Fmt::shortDate($bookedAt),
            counterpartyName: $counterpartyName,
            categoryId: $categoryId,
            categoryName: $categoryName,
            amount: Money::ofMinor(self::toInt($row->display_minor), $displayCurrency),
            secondaryAmount: $secondaryAmount,
            counterpartySlug: $counterpartySlug,
        );
    }
}
