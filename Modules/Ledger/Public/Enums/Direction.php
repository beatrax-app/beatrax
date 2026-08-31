<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Several modules persist this two-way split on their own rows
// (anomaly_alerts, drift_alerts, recurring_series). Mapping to and from a
// transaction's type lives on TransactionType -- directionOf() and
// externalMovementValuesFor() -- which owns the cases it would restate.
enum Direction: string
{
    case Expense = 'expense';

    case Income = 'income';
}
