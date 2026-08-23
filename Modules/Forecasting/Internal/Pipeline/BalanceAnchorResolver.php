<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Dto\BalanceAnchorDto;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use stdClass;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md#balance-anchor-resolution
 */
final readonly class BalanceAnchorResolver
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private BaseCurrency $baseCurrency,
        private AccountBalanceQuery $balances,
    ) {}

    public function forAccount(int $accountId, User $user): BalanceAnchorDto
    {
        /** @var Account $account */
        $account = Account::query()
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $defaultCurrency = self::toString($account->getAttribute('default_currency'));

        // A card takes its statement, then the reader-typed balance, then zero,
        // rather than the ledger balance: summing would double-count the
        // billing events the projection is about to re-emit.
        if (self::toString($account->getAttribute('kind')) === AccountKind::IcsCard->value) {
            return $this->fromCardStatements($accountId, $user, $defaultCurrency)
                ?? ($this->hasReaderTypedBalance($account)
                    ? $this->fromLedgerBalance($accountId, $user, $defaultCurrency)
                    : $this->icsCardZeroAnchor($accountId, $defaultCurrency));
        }

        return $this->fromLedgerBalance($accountId, $user, $defaultCurrency);
    }

    // Where the account stands today, the one figure the dashboard, pots and
    // /reconcile also read. Anchored on a statement that closed on 11 April
    // instead, the forecast opened EUR929.98 under the money on the account
    // and the calendar's line stepped down on today to meet it.
    private function fromLedgerBalance(int $accountId, User $user, string $defaultCurrency): BalanceAnchorDto
    {
        $asOf = $this->clock->now()->startOfDay();

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: $this->balances->currentBalanceAsOf($accountId, $user, $asOf),
            currency: $defaultCurrency !== '' ? $defaultCurrency : $this->baseCurrency->code(),
            source: 'sum_of_transactions',
        );
    }

    private function icsCardZeroAnchor(int $accountId, string $defaultCurrency): BalanceAnchorDto
    {
        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: 0,
            currency: $defaultCurrency !== '' ? $defaultCurrency : $this->baseCurrency->code(),
            source: 'ics_card_zero_anchor',
        );
    }

    // null means the card has no statement imported yet, and the caller falls
    // through to the reader's own figure.
    private function fromCardStatements(int $accountId, User $user, string $defaultCurrency): ?BalanceAnchorDto
    {
        $row = $this->db->connection()->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        // open_balance_minor is the amount owed, stored positive. A running
        // balance is a position, so owing it makes it negative.
        $openBalance = self::toInt($row->open_balance_minor);

        $rawPeriodEnd = self::toString($row->period_end ?? null);
        $closedOn = $rawPeriodEnd !== ''
            ? CarbonImmutable::parse($rawPeriodEnd)
            : $this->clock->now();

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: -$openBalance + $this->chargedSince($accountId, $user->id, $closedOn),
            currency: $defaultCurrency !== '' ? $defaultCurrency : Currency::Eur->value,
            source: 'ics_card_statement',
        );
    }

    // What the card has run up since its statement closed. Without it the
    // forecast opens on the balance the card carried at close and every charge
    // made since is missing from the curve. Bounded at today for the same
    // reason the ledger balance is: a charge dated ahead is not owed yet.
    private function chargedSince(int $accountId, int $userId, CarbonImmutable $closedOn): int
    {
        return (int) $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('posted_at', '>', $closedOn->toDateString())
            ->where('posted_at', '<=', $this->clock->now()->startOfDay()->toDateString())
            ->sum('settled_amount_minor');
    }

    private function hasReaderTypedBalance(Account $account): bool
    {
        return is_numeric($account->getAttribute('opening_balance_minor'));
    }
}
