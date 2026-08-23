<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use DateTimeInterface;
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
            return $this->fromCardStatements($accountId, $user->id)
                ?? $this->fromUserInputOpeningBalance($account)
                ?? $this->icsCardZeroAnchor($accountId, $defaultCurrency);
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
            asOfDate: $asOf,
            source: 'sum_of_transactions',
        );
    }

    private function icsCardZeroAnchor(int $accountId, string $defaultCurrency): BalanceAnchorDto
    {
        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: 0,
            currency: $defaultCurrency !== '' ? $defaultCurrency : $this->baseCurrency->code(),
            asOfDate: $this->clock->now()->startOfDay(),
            source: 'ics_card_zero_anchor',
        );
    }

    // null means the card has no statement imported yet, and the caller falls
    // through to the reader's own figure.
    private function fromCardStatements(int $accountId, int $userId): ?BalanceAnchorDto
    {
        $row = $this->db->connection()->table('card_statements')
            ->where('user_id', $userId)
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
        $signedAnchor = -$openBalance;

        $rawPeriodEnd = self::toString($row->period_end ?? null);
        $asOf = $rawPeriodEnd !== ''
            ? CarbonImmutable::parse($rawPeriodEnd)
            : $this->clock->now();

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: $signedAnchor,
            currency: Currency::Eur->value,
            asOfDate: $asOf,
            source: 'ics_card_statement',
        );
    }

    private function fromUserInputOpeningBalance(Account $account): ?BalanceAnchorDto
    {
        $rawMinor = $account->getAttribute('opening_balance_minor');
        if (! is_numeric($rawMinor)) {
            return null;
        }
        $rawAsOf = $account->getAttribute('opening_balance_as_of_date');
        $asOfString = self::carbonOrStringToString($rawAsOf);
        if ($asOfString === '') {
            return null;
        }

        $defaultCurrency = self::toString($account->getAttribute('default_currency'));
        if ($defaultCurrency === '') {
            $defaultCurrency = $this->baseCurrency->code();
        }

        return new BalanceAnchorDto(
            accountId: self::toInt($account->getAttribute('id')),
            openingBalanceMinor: (int) $rawMinor,
            currency: $defaultCurrency,
            asOfDate: CarbonImmutable::parse($asOfString),
            source: 'user_input_opening_balance',
        );
    }

    private static function carbonOrStringToString(mixed $value): string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->toDateString();
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return self::toString($value);
    }
}
