<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Distinct from Ledger\Direction, which is a transaction's money direction.
// The column stays string; this enum is the one canonical spelling callers
// map through.
enum CategoryKind: string
{
    case Income = 'income';

    case Expense = 'expense';

    case Transfer = 'transfer';
}
