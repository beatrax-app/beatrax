<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Dto\BookedFutureRowDto;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// A row the ledger already holds whose posted_at is still ahead. It is not
// money the account is holding — no balance counts it, and none should — but
// it is a known, dated movement, which is the only thing a forward-looking
// surface was ever missing about it.
/**
 * @link ../../../../.docs/features/forecasting/architecture.md#booked-future-dated-rows
 */
final readonly class BookedFutureRowQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    /**
     * @param  list<int>|null  $accountIds  null = every account the reader owns
     * @return list<BookedFutureRowDto> ordered by posted_at then id
     */
    public function between(
        User $user,
        CarbonImmutable $afterDate,
        CarbonImmutable $throughDate,
        ?array $accountIds = null,
    ): array {
        if ($throughDate->lessThanOrEqualTo($afterDate)) {
            return [];
        }

        $rows = $this->window($user, $afterDate, $throughDate, $accountIds)
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->orderBy('transactions.posted_at')
            ->orderBy('transactions.id')
            ->get([
                'transactions.id',
                'transactions.account_id',
                'transactions.posted_at',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.counterparty_name',
                'counterparties.slug as counterparty_slug',
            ]);

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $minor = self::toInt($row->settled_amount_minor);
            $settled = Money::tryOfMinor($minor, self::toString($row->settled_currency));
            $postedAt = SafeDate::parseDayOrNull(self::toString($row->posted_at));
            if ($settled === null || $postedAt === null) {
                continue;
            }

            $result[] = new BookedFutureRowDto(
                transactionId: self::toInt($row->id),
                accountId: self::toInt($row->account_id),
                postedAt: $postedAt,
                settled: $settled,
                direction: $minor < 0 ? Direction::Expense : Direction::Income,
                counterpartyName: $this->counterpartyName($row, self::toInt($user->id)),
                counterpartySlug: $this->counterpartySlug($row),
            );
        }

        return $result;
    }

    // Whether the reader has anything dated ahead at all, asked without
    // hydrating and decrypting every row to find out.
    public function hasAnyAfter(User $user, CarbonImmutable $afterDate, CarbonImmutable $throughDate): bool
    {
        if ($throughDate->lessThanOrEqualTo($afterDate)) {
            return false;
        }

        return $this->window($user, $afterDate, $throughDate, null)->exists();
    }

    /**
     * @param  list<int>|null  $accountIds
     */
    private function window(
        User $user,
        CarbonImmutable $afterDate,
        CarbonImmutable $throughDate,
        ?array $accountIds,
    ): Builder {
        $query = $this->db->connection()
            ->table('transactions')
            ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->where('transactions.user_id', $user->id)
            ->where('transactions.posted_at', '>', $afterDate->toDateString())
            ->where('transactions.posted_at', '<=', $throughDate->toDateString())
            // A row before its account's baseline is already inside the opening
            // figure, so counting it again would move the curve twice.
            ->whereRaw(AccountStartingBalanceQuery::AT_OR_AFTER_BASELINE_SQL);

        if ($accountIds !== null) {
            $query->whereIn('transactions.account_id', $accountIds);
        }

        return $query;
    }

    private function counterpartyName(stdClass $row, int $userId): ?string
    {
        if ($row->counterparty_name === null) {
            return null;
        }

        $decrypted = $this->codec->decryptValue(
            'transactions',
            'counterparty_name',
            self::toString($row->counterparty_name),
            $userId,
            ($this->session)(),
        )['value'];

        return $decrypted === '' ? null : $decrypted;
    }

    private function counterpartySlug(stdClass $row): ?string
    {
        $slug = self::toString($row->counterparty_slug ?? null);

        return $slug === '' ? null : $slug;
    }
}
