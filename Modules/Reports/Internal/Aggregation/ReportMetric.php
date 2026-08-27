<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use InvalidArgumentException;
use Modules\Ledger\Public\Enums\TransactionType;

// The report vocabulary also has `net_worth`, but that is a balance series the
// aggregator answers on its own, so it never reaches a dimension query. The
// wider set a reader can select is Enums\ReportMetricSelection; this one stays
// strict because a value arriving here has already been through that gate.
enum ReportMetric: string
{
    case Spend = 'spend';

    case Income = 'income';

    case Net = 'net';

    /** @var list<string> */
    private const array DISCLOSED_TYPES = [
        TransactionType::Fee->value,
        TransactionType::Adjustment->value,
        TransactionType::Refund->value,
    ];

    public static function fromMetric(string $metric): self
    {
        return self::tryFrom($metric) ?? throw new InvalidArgumentException("Unknown report metric: {$metric}");
    }

    // A refund is a reversal of an expense, never income of its own: counting it
    // in both would make `income - spend` and `net` disagree about it. Nothing
    // outside these three plus DISCLOSED_TYPES is money the reader moved --
    // transfer_in/transfer_out are the two halves of one internal move.
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

    // Real movement no metric is defined over. Derived by subtraction rather
    // than listed, so a type this metric already counts can never be reported
    // twice -- once in the total and again beside it.
    /**
     * @return list<string>
     */
    public function disclosedTypes(): array
    {
        return array_values(array_diff(self::DISCLOSED_TYPES, $this->types()));
    }

    public function disclosesRefunds(): bool
    {
        return in_array(TransactionType::Refund->value, $this->disclosedTypes(), true);
    }

    // Connection::raw() needs a literal-string, so the caller's column
    // prefix is matched against a fixed set rather than interpolated.
    /**
     * @return literal-string
     */
    public function sumExpr(string $prefix = ''): string
    {
        $column = match ($prefix) {
            '' => 'settled_amount_minor',
            't.' => 't.settled_amount_minor',
            'ts.' => 'ts.settled_amount_minor',
            default => throw new InvalidArgumentException("Unknown column prefix: {$prefix}"),
        };

        return match ($this) {
            self::Spend => 'SUM(-'.$column.')',
            self::Income, self::Net => 'SUM('.$column.')',
        };
    }
}
