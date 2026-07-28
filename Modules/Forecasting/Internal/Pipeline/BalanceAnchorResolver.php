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
use stdClass;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class BalanceAnchorResolver
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
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

        if ($kind === 'asn') {
            $anchor = $this->fromStatementSummaries($accountId, $user->id);
            if ($anchor !== null) {
                return $anchor;
            }
        } elseif ($kind === 'ics_card') {
            $anchor = $this->fromCardStatements($accountId, $user->id);
            if ($anchor !== null) {
                return $anchor;
            }
        }

        // Cross-kind fallback: when no statement-level anchor was
        // available (or when the kind is paypal / CSV-only), honour
        // the user-input opening_balance on the account row.
        $anchor = $this->fromUserInputOpeningBalance($account);
        if ($anchor !== null) {
            return $anchor;
        }

        // For ICS card accounts with no statement AND no user-input
        // opening balance, default to zero rather than summing every
        // historical transaction — summing would double-count the
        // historical billing events the projection is about to re-emit.
        if ($kind === 'ics_card') {
            return new BalanceAnchorDto(
                accountId: $accountId,
                openingBalanceMinor: 0,
                currency: $defaultCurrency !== '' ? $defaultCurrency : 'EUR',
                asOfDate: $this->clock->now()->startOfDay(),
                source: 'ics_card_zero_anchor',
            );
        }

        return $this->fromTransactionsSum($accountId, $user->id, $defaultCurrency);
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
            $currency = 'EUR';
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
        // `open_balance_minor` is the absolute amount still owed
        // (positive); the signed running-balance position is negated
        // since the user OWES that amount to the card vendor.
        $openBalance = self::toInt($row->open_balance_minor);
        $signedAnchor = -$openBalance;

        $rawPeriodEnd = self::toString($row->period_end ?? null);
        $asOf = $rawPeriodEnd !== ''
            ? CarbonImmutable::parse($rawPeriodEnd)
            : $this->clock->now();

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: $signedAnchor,
            currency: 'EUR',
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
            $defaultCurrency = 'EUR';
        }

        return new BalanceAnchorDto(
            accountId: self::toInt($account->getAttribute('id')),
            openingBalanceMinor: (int) $rawMinor,
            currency: $defaultCurrency,
            asOfDate: CarbonImmutable::parse($asOfString),
            source: 'user_input_opening_balance',
        );
    }

    private function fromTransactionsSum(int $accountId, int $userId, string $defaultCurrency): BalanceAnchorDto
    {
        $sum = (int) $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->sum('amount_minor');

        if ($defaultCurrency === '') {
            $defaultCurrency = 'EUR';
        }

        return new BalanceAnchorDto(
            accountId: $accountId,
            openingBalanceMinor: $sum,
            currency: $defaultCurrency,
            asOfDate: CarbonImmutable::parse('1970-01-01'),
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
