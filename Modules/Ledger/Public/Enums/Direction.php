<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Money out (Expense) vs money in (Income) — the two-way split several
// modules persist on their own rows (anomaly_alerts, drift_alerts,
// recurring_series) and derive from a transaction's type. Owning the
// type<->direction mapping here keeps it from being re-derived per caller.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
enum Direction: string
{
    case Expense = 'expense';

    case Income = 'income';

    // Which direction a transaction type represents: income, transfer_in and
    // refund are money-in; every other type is money-out. (When
    // TransactionType becomes an enum this becomes TransactionType::direction.)
    public static function fromTransactionType(string $type): self
    {
        return match ($type) {
            'income', 'transfer_in', 'refund' => self::Income,
            default => self::Expense,
        };
    }

    // The inverse: the transaction types that make up this direction, used to
    // filter a merchant's history to same-direction rows.
    /** @return list<string> */
    public function transactionTypes(): array
    {
        return $this === self::Income
            ? ['income', 'transfer_in', 'refund']
            : ['expense', 'transfer_out', 'fee', 'adjustment'];
    }
}
