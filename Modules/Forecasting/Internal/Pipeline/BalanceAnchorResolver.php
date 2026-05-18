<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Dto\BalanceAnchorDto;
use Modules\Ledger\Models\Account;
use stdClass;

/**
 * Resolves the per-account opening-balance anchor for a projection run.
 *
 * The resolver routes by `accounts.kind` to the most authoritative
 * starting point available:
 *
 *   - `asn_bank` → most recent `statement_summaries.closing_balance_minor`
 *   - `ics_card` → most recent `card_statements` "open balance" (the
 *     remaining amount owed; surfaced as a SIGNED running-balance
 *     position so the projection math composes cleanly)
 *   - `paypal` or any other kind → `accounts.opening_balance_minor`
 *     when the user entered one; otherwise the fallback below
 *
 * Fallback (used when no statement / user-input anchor is available):
 * the resolver sums every existing transaction on the account from
 * scratch (`asOf=1970-01-01`). The UI surfaces this case with the
 * "Opening balance not set" banner so the user knows the projection's
 * starting point is approximated.
 *
 * The returned `BalanceAnchorDto.source` label is the audit ribbon's
 * input — `asn_statement_summary`, `ics_card_statement`,
 * `user_input_opening_balance`, or `sum_of_transactions`.
 *
 * Cross-user 404: a missing or cross-user account raises
 * `Illuminate\Database\Eloquent\ModelNotFoundException`, which the HTTP
 * kernel converts to a 404 (Phase 5 cross-user 404 precedent).
 */
final readonly class BalanceAnchorResolver
{
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

        if ($kind === 'asn_bank') {
            $anchor = $this->fromStatementSummaries($accountId, $user->id);
            if ($anchor !== null) {
                return $anchor;
            }
        } elseif ($kind === 'ics_card') {
            $anchor = $this->fromCardStatements($accountId, $user->id);
            if ($anchor !== null) {
                return $anchor;
            }
        } else {
            $anchor = $this->fromUserInputOpeningBalance($account);
            if ($anchor !== null) {
                return $anchor;
            }
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
        // `card_statements.open_balance_minor` is the absolute amount
        // still owed on the card (positive). The signed running-balance
        // representation a per-account forecast tracks is the user's
        // position relative to the card vendor: they OWE the open
        // balance, so the position is the negative of that figure.
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
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
