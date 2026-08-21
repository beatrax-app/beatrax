<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Several modules persist this two-way split on their own rows
// (anomaly_alerts, drift_alerts, recurring_series) and derive it from a
// transaction's type; owning the mapping here stops it being re-derived.
enum Direction: string
{
    case Expense = 'expense';

    case Income = 'income';

    public static function fromTransactionType(string $type): self
    {
        return match ($type) {
            'income', 'transfer_in', 'refund' => self::Income,
            default => self::Expense,
        };
    }

    /** @return list<string> */
    public function transactionTypes(): array
    {
        return $this === self::Income
            ? ['income', 'transfer_in', 'refund']
            : ['expense', 'transfer_out', 'fee', 'adjustment'];
    }
}
