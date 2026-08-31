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

        // A card takes its statement first; without one it falls back to the
        // ledger balance every other kind takes. Anchored at zero instead, the
        // all-accounts curve stood the card's whole debt above the net worth
        // on the dashboard one click away -- EUR6,681.85 against EUR6,127.85.
        if (self::toString($account->getAttribute('kind')) === AccountKind::IcsCard->value) {
            return $this->fromCardStatements($accountId, $user, $defaultCurrency)
                ?? $this->fromLedgerBalance($accountId, $user, $defaultCurrency);
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
        $currency = $defaultCurrency !== '' ? $defaultCurrency : $this->baseCurrency->code();

        // A projection runs in one currency, so it opens on the line the
        // account is denominated in and leaves any other line it holds out.
        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: $this->balances->currentBalanceAsOf($accountId, $user, $asOf)->in($currency),
            currency: $currency,
            source: 'sum_of_transactions',
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

        $currency = $defaultCurrency !== '' ? $defaultCurrency : Currency::Eur->value;

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: -$openBalance + $this->chargedSince($accountId, $user->id, $closedOn, $currency),
            currency: $currency,
            source: 'ics_card_statement',
        );
    }

    // What the card has run up since its statement closed. Without it the
    // forecast opens on the balance the card carried at close and every charge
    // made since is missing from the curve. Bounded at today because a charge
    // dated ahead is not owed yet, and to one currency because the anchor is.
    private function chargedSince(int $accountId, int $userId, CarbonImmutable $closedOn, string $currency): int
    {
        return (int) $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('settled_currency', $currency)
            ->where('posted_at', '>', $closedOn->toDateString())
            ->where('posted_at', '<=', $this->clock->now()->startOfDay()->toDateString())
            ->sum('settled_amount_minor');
    }
}
