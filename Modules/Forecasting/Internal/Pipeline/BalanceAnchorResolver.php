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
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use stdClass;

final readonly class BalanceAnchorResolver
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private BaseCurrency $baseCurrency,
        private AccountStartingBalanceQuery $startingBalances,
    ) {}

    public function forAccount(int $accountId, User $user): BalanceAnchorDto
    {
        /** @var Account $account */
        $account = Account::query()
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $kind = self::toString($account->getAttribute('kind'));
        $defaultCurrency = self::toString($account->getAttribute('default_currency'));

        $anchor = $this->fromStatementAnchor($kind, $accountId, $user->id);
        if ($anchor !== null) {
            return $anchor;
        }

        // A card takes its reader-typed balance, or zero, rather than a
        // transaction sum: summing would double-count the billing events the
        // projection is about to re-emit. Every other kind sums, and that sum
        // already opens on the reader's figure as a dated baseline.
        return $kind === AccountKind::IcsCard->value
            ? $this->fromUserInputOpeningBalance($account) ?? $this->icsCardZeroAnchor($accountId, $defaultCurrency)
            : $this->fromTransactionsSum($accountId, $user, $defaultCurrency);
    }

    // null means no statement exists at all — an API-connected account, or one
    // with nothing imported yet — and the caller falls through.
    private function fromStatementAnchor(string $kind, int $accountId, int $userId): ?BalanceAnchorDto
    {
        return $kind === AccountKind::IcsCard->value
            ? $this->fromCardStatements($accountId, $userId)
            : $this->fromStatementSummaries($accountId, $userId);
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

    private function fromStatementSummaries(int $accountId, int $userId): ?BalanceAnchorDto
    {
        $row = $this->db->connection()->table('statement_summaries')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->whereNotNull('closing_balance_minor')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        $currency = self::toString($row->closing_balance_currency ?? null);
        if ($currency === '') {
            $currency = $this->baseCurrency->code();
        }
        $rawAsOf = self::toString($row->closing_balance_date ?? $row->period_end ?? null);
        $asOf = $rawAsOf !== ''
            ? CarbonImmutable::parse($rawAsOf)
            : $this->clock->now();

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: self::toInt($row->closing_balance_minor),
            currency: $currency,
            asOfDate: $asOf,
            source: 'asn_statement_summary',
        );
    }

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

    // Bounded at today on posted_at, which is the column the calendar's own
    // past-day line sums: unbounded, a future-dated row was counted as money
    // already held, and the line stepped by the whole of next week on today.
    private function fromTransactionsSum(int $accountId, User $user, string $defaultCurrency): BalanceAnchorDto
    {
        $asOf = $this->clock->now()->startOfDay();
        $baseline = $this->startingBalances->forAccount($accountId, $user);

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('posted_at', '<=', $asOf->toDateString());

        // The baseline already holds everything up to its own date, so
        // counting a row posted before it would count that history twice.
        if ($baseline['date'] !== null) {
            $rows->where('posted_at', '>=', $baseline['date']->toDateString());
        }

        $sum = $baseline['minorUnits'] + (int) $rows->sum('settled_amount_minor');

        if ($defaultCurrency === '') {
            $defaultCurrency = $this->baseCurrency->code();
        }

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: $sum,
            currency: $defaultCurrency,
            asOfDate: $asOf,
            source: 'sum_of_transactions',
        );
    }

    private static function carbonOrStringToString(mixed $value): string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->toDateString();
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return self::toString($value);
    }
}
