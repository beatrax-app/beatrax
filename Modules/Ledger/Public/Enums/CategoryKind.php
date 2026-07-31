<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// How a category classifies its transactions: `income`, `expense`, or
// `transfer`. Distinct from Ledger\Direction (which is a transaction's
// money direction). The column stays string; this enum is the one
// canonical spelling callers map through.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
enum CategoryKind: string
{
    case Income = 'income';

    case Expense = 'expense';

    case Transfer = 'transfer';
}
