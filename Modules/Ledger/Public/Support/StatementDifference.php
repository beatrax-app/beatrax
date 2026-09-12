<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Modules\Ingestion\Public\Enums\StatementExtraKey;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../../../.docs/features/ingestion/a-statement-that-did-not-check-its-own-arithmetic.md
 */
final readonly class StatementDifference
{
    // Which way the gap runs, as the sentence that says so. The sign is the
    // only thing telling the two apart and it is read backwards easily, so the
    // reading is made once here rather than at each screen that renders it.
    private const string FALLS_SHORT = 'core::statement.falls_short_of_closing';

    private const string OVERSHOOTS = 'core::statement.overshoots_closing';

    private function __construct(
        private int $minor,
        private Money $money,
    ) {}

    // For a caller holding a whole `statement_summaries` row rather than a
    // figure it has already read out of one.
    public static function readFrom(mixed $extras, mixed $currency): ?self
    {
        return self::ofMinor(self::statedMinor($extras), $currency);
    }

    // Null wherever there is nothing a reader could act on: the parser
    // publishes no zero, and a figure whose currency column names no currency
    // is a bare integer rather than money.
    public static function ofMinor(?int $minor, mixed $currency): ?self
    {
        $money = ($minor === null || ! is_string($currency))
            ? null
            : Money::tryOfMinor(abs($minor), $currency);

        return ($minor === null || $money === null) ? null : new self($minor, $money);
    }

    // The column arrives as JSON text through a query builder and as an array
    // through the model cast, and this table has readers of both kinds. Absent
    // both on a statement that added up and on one there was nothing to check,
    // which is why neither answer reaches a screen.
    public static function statedMinor(mixed $extras): ?int
    {
        $decoded = is_string($extras) ? json_decode($extras, true) : $extras;
        $stated = is_array($decoded)
            ? ($decoded[StatementExtraKey::StatementDifference->value] ?? null)
            : null;

        return is_int($stated) && $stated !== 0 ? $stated : null;
    }

    // Positive is `closing - (opening + rows)` above zero: the two balances
    // move further apart than the rows account for, so the opening balance
    // plus the rows lands BELOW the closing balance the file states.
    public function copyKey(): string
    {
        return $this->minor > 0 ? self::FALLS_SHORT : self::OVERSHOOTS;
    }

    // Absolute, because the line copyKey() picks carries the direction in
    // words. Signed as well, the figure would state it twice and the two
    // spellings could contradict each other.
    public function amount(): string
    {
        return $this->money->format();
    }
}
