<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Modules\Ledger\Public\ValueObjects\MoneyInput;

// The one reader of an `amount:` token, and it decides nothing about money
// itself. A regex of its own stood here, blind to the group marks MoneyInput
// accepts: `amount:1.234,56` -- what the app writes EUR 1,234.56 as for a
// Dutch reader -- matched as far as `1.23` and filtered at a thousandth of it.
/**
 * @link ../../../../.docs/features/search/architecture.md
 */
final class AmountToken
{
    private const string RANGE_SEPARATOR = '-';

    // Null where the token names no amount this reader's money can hold. The
    // caller then leaves the token in the text query rather than stripping it,
    // so an unreadable bound narrows the search instead of silently widening it
    // to everything -- the rule an unresolvable account: or category: follows.
    /**
     * @return ?array{0: ?string, 1: ?string} min and max as decimal strings; null on a side this token does not state
     */
    public static function bound(string $raw, string $readerCurrency): ?array
    {
        $token = trim($raw);

        return match (true) {
            $token === '' => null,
            str_starts_with($token, '>') => self::atLeast(substr($token, 1), $readerCurrency),
            str_starts_with($token, '<') => self::atMost(substr($token, 1), $readerCurrency),
            str_contains($token, self::RANGE_SEPARATOR) => self::between($token, $readerCurrency),
            default => self::exactly($token, $readerCurrency),
        };
    }

    /**
     * @return ?array{0: ?string, 1: ?string}
     */
    private static function atLeast(string $raw, string $readerCurrency): ?array
    {
        $min = self::figure($raw, $readerCurrency);

        return $min === null ? null : [$min, null];
    }

    /**
     * @return ?array{0: ?string, 1: ?string}
     */
    private static function atMost(string $raw, string $readerCurrency): ?array
    {
        $max = self::figure($raw, $readerCurrency);

        return $max === null ? null : [null, $max];
    }

    /**
     * @return ?array{0: ?string, 1: ?string}
     */
    private static function between(string $token, string $readerCurrency): ?array
    {
        [$low, $high] = array_pad(explode(self::RANGE_SEPARATOR, $token, 2), 2, '');

        $min = self::figure($low, $readerCurrency);
        $max = self::figure($high, $readerCurrency);

        return $min === null || $max === null ? null : [$min, $max];
    }

    /**
     * @return ?array{0: ?string, 1: ?string}
     */
    private static function exactly(string $token, string $readerCurrency): ?array
    {
        $figure = self::figure($token, $readerCurrency);

        return $figure === null ? null : [$figure, $figure];
    }

    // Answered in the machine-readable spelling SearchFilters documents, so the
    // reader's own separators travel no further than this call. A negative is
    // refused: the bound is applied to ABS(), where it would match every row.
    private static function figure(string $raw, string $readerCurrency): ?string
    {
        $minor = MoneyInput::tryToMinor(trim($raw), $readerCurrency);

        return $minor === null || $minor < 0
            ? null
            : MoneyInput::toDecimalString($minor, $readerCurrency);
    }
}
