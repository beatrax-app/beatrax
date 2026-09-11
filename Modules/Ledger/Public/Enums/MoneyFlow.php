<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

use InvalidArgumentException;
use Modules\Import\Public\Enums\PaymentType;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#moneyflow--the-one-definition-of-spend-income-and-net
 */
enum MoneyFlow: string
{
    case Spend = 'spend';

    case Income = 'income';

    case Net = 'net';

    // The type LABELS each flow owns, which only the Reports disclosure reads.
    // Which ROWS a flow counts is predicate(), and every SUM asks that one.
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

    // Which rows this flow counts: `type` decides membership, the SIGN decides
    // the side, and a refund goes to spend whichever way it is signed. A label
    // is not a direction — nothing writes `type` from the money.
    /**
     * @return array{literal-string, list<string>}
     */
    public function predicate(string $prefix = ''): array
    {
        [$type, $paymentType, $amount, $pair] = self::columns($prefix);

        // A row naming a partner is one leg of a move inside the household,
        // whatever its own type says. The two columns are announced by separate
        // writers and merge on separate clocks, so a peer's link outlives this
        // device's retype and money that never left reads as spend.
        $counted = '('.$type.' IN (?, ?, ?) AND '.$pair.' IS NULL)';
        $refunded = '('.$paymentType.' = ? OR '.$type.' = ?)';
        $rollup = self::Net->types();

        return match ($this) {
            self::Net => [$counted, $rollup],
            self::Income => [$counted.' AND NOT '.$refunded.' AND '.$amount.' > 0', [...$rollup, ...self::refundBindings()]],
            self::Spend => [$counted.' AND ('.$refunded.' OR '.$amount.' <= 0)', [...$rollup, ...self::refundBindings()]],
        };
    }

    // Marked in either place and meaning the same in both: the payment-type
    // detector writes one, and a reader labelling by hand writes the other.
    /**
     * @return list<string>
     */
    private static function refundBindings(): array
    {
        return [PaymentType::Refund->value, TransactionType::Refund->value];
    }

    // Matched against a fixed set rather than interpolated: whereRaw() and
    // selectRaw() take a literal-string, and concatenating a caller's prefix
    // would not be one.
    /**
     * @return array{literal-string, literal-string, literal-string, literal-string}
     */
    private static function columns(string $prefix): array
    {
        return match ($prefix) {
            '' => ['type', "COALESCE(payment_type, '')", 'settled_amount_minor', 'pair_transaction_id'],
            't.' => ['t.type', "COALESCE(t.payment_type, '')", 't.settled_amount_minor', 't.pair_transaction_id'],
            'transactions.' => ['transactions.type', "COALESCE(transactions.payment_type, '')", 'transactions.settled_amount_minor', 'transactions.pair_transaction_id'],
            default => throw new InvalidArgumentException("Unknown column prefix: {$prefix}"),
        };
    }
}
