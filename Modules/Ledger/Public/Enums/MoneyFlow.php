<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#moneyflow--the-one-definition-of-spend-income-and-net
 */
enum MoneyFlow: string
{
    case Spend = 'spend';

    case Income = 'income';

    case Net = 'net';

    // A refund reverses an expense, so it reduces spend rather than adding to
    // income; counted as income, `income - spend` and `net` would disagree
    // about it. transfer_in/transfer_out are the two halves of one internal
    // move, and no rollup counts them.
    /**
     * @return list<string>
     */
    public function types(): array
    {
        return match ($this) {
            self::Spend => [TransactionType::Expense->value, TransactionType::Refund->value],
            self::Income => [TransactionType::Income->value],
            self::Net => [TransactionType::Expense->value, TransactionType::Income->value, TransactionType::Refund->value],
        };
    }
}
