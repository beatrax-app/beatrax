<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Illuminate\Database\Query\Builder;

// A statement prints its balances in one currency and records which in the
// column beside each figure; an account is denominated by its own
// `default_currency`. The two are matched by IBAN, which does not make them the
// same money — a multi-currency IBAN prints a statement per line.
/**
 * @link ../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#a-statement-is-only-this-accounts-balance-in-this-accounts-currency
 */
final class StatementDenomination
{
    private const string ACCOUNT_ALIAS = 'denominating_account';

    // Narrows a `statement_summaries` query to the rows whose figure really is
    // this account's balance. A row written before the currency column existed
    // carries null and is read as the account's own, which is what it was.
    public static function boundToTheAccount(Builder $query, string $currencyColumn): Builder
    {
        return $query
            ->join(
                'accounts as '.self::ACCOUNT_ALIAS,
                self::ACCOUNT_ALIAS.'.id',
                '=',
                'statement_summaries.account_id',
            )
            ->where(static function (Builder $sameMoney) use ($currencyColumn): void {
                $column = 'statement_summaries.'.$currencyColumn;

                $sameMoney->whereNull($column)
                    ->orWhere($column, '')
                    ->orWhereColumn($column, self::ACCOUNT_ALIAS.'.default_currency');
            });
    }

    // The same question for `card_statements`, which keeps one `currency` for
    // the whole statement rather than one per figure.
    public static function cardStatementBoundToItsAccount(Builder $query): Builder
    {
        return $query
            ->join(
                'accounts as '.self::ACCOUNT_ALIAS,
                self::ACCOUNT_ALIAS.'.id',
                '=',
                'card_statements.account_id',
            )
            ->where(static function (Builder $sameMoney): void {
                $sameMoney->whereNull('card_statements.currency')
                    ->orWhere('card_statements.currency', '')
                    ->orWhereColumn('card_statements.currency', self::ACCOUNT_ALIAS.'.default_currency');
            });
    }
}
