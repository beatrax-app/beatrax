<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Several modules persist this two-way split on their own rows
// (anomaly_alerts, drift_alerts, recurring_series). The mapping to and from a
// transaction's type lives on TransactionType, which owns the cases it would
// otherwise have to restate: see directionOf() and valuesFor() there.
enum Direction: string
{
    case Expense = 'expense';

    case Income = 'income';
}
