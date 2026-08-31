<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The DB-layer BEFORE INSERT/UPDATE triggers reject any value outside this
// set regardless of write path; this enum is the code-side source of truth
// the write paths, the split rules and the direction derivation read from.
enum TransactionType: string
{
    case Expense = 'expense';

    case Income = 'income';

    case TransferOut = 'transfer_out';

    case TransferIn = 'transfer_in';

    case Fee = 'fee';

    case Refund = 'refund';

    case Adjustment = 'adjustment';

    // The transfer legs own the paired equal-and-opposite invariant via
    // pair_transaction_id.
    public function isTransfer(): bool
    {
        return $this === self::TransferOut || $this === self::TransferIn;
    }

    // A transfer can never carry category legs — SaveTransactionSplit and the
    // reclassify auto-unsplit coordination both depend on that.
    public function isSplittable(): bool
    {
        return ! $this->isTransfer();
    }

    // Listed per case rather than defaulted, for the same reason
    // isExternalMovement() below is: a type added later has to state which way
    // its money moves. Under a `default` arm it would have joined the expense
    // side unannounced, which is how transfer_out came to be judged as spend.
    public function direction(): Direction
    {
        return match ($this) {
            self::Income, self::TransferIn, self::Refund => Direction::Income,
            self::Expense, self::TransferOut, self::Fee, self::Adjustment => Direction::Expense,
        };
    }

    // Coerces rather than throwing, because the callers read `type` off a row
    // array and an unreadable one has to resolve to something. Expense is what
    // the mapping this replaced fell through to, and the DB triggers already
    // reject anything outside the set on the way in.
    public static function directionOf(mixed $type): Direction
    {
        return (is_string($type) ? self::tryFrom($type) : null)?->direction() ?? Direction::Expense;
    }

    // Money crossing between the reader and someone else, which is a different
    // question from which way it moved: a transfer_out genuinely lowers the
    // balance, and is still only the reader shifting their own money between
    // two of their own accounts. An adjustment reconciles against nobody.
    /**
     * @link ../../../../.docs/features/ledger/architecture.md#transactiontype--direction-is-not-the-question-anomaly-asks
     */
    public function isExternalMovement(): bool
    {
        return match ($this) {
            self::Expense, self::Income, self::Fee, self::Refund => true,
            self::TransferOut, self::TransferIn, self::Adjustment => false,
        };
    }

    // Coerces like directionOf(), for the same reason, and to the answer that
    // leaves an unreadable type judged exactly as it was before this predicate
    // existed — abstaining on it would turn a bad `type` into silence.
    public static function isExternalMovementOf(mixed $type): bool
    {
        return (is_string($type) ? self::tryFrom($type) : null)?->isExternalMovement() ?? true;
    }

    // Derived from the cases rather than listed, so a case added without an
    // answer to either question cannot quietly fall out of, or into, the set an
    // anomaly detector scans. There is deliberately no "every type facing this
    // direction" sibling: the three callers that had one were all asking this.
    /** @return list<string> */
    public static function externalMovementValuesFor(Direction $direction): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(
                self::cases(),
                static fn (self $type): bool => $type->direction() === $direction && $type->isExternalMovement(),
            ),
        ));
    }

    /** @return list<string> */
    public static function transferValues(): array
    {
        return [self::TransferOut->value, self::TransferIn->value];
    }
}
