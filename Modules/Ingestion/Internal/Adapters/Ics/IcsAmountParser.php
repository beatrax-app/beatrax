<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class IcsAmountParser
{
    // The currency list is closed rather than \b[A-Z]{3}\b, which would
    // swallow any three-letter token sitting beside the amount. The glyphs
    // come from Money so this does not become a second list of them.
    private const array ISO_CODES = ['EUR', 'USD', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD'];

    // A statement has two amount columns, and the left one is not in euros:
    // ics-sample-1.txt prints "50,00 USD" and "8,99 GBP" there, and the adapter
    // reads that column as the row's own amount. $currencyCode is the one the
    // caller just read off the row; without one the euro column's scale holds.
    public function parse(string $raw, ?string $currencyCode = null): int
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new InvalidAmountException('Empty amount string.');
        }

        $stripped = preg_replace(self::currencyPattern(), '', $trimmed);
        if ($stripped === null) {
            throw new InvalidAmountException(sprintf('Invalid amount string: %s', $raw));
        }
        $stripped = trim($stripped);

        $sign = 1;
        if (str_starts_with($stripped, '-')) {
            $sign = -1;
            $stripped = substr($stripped, 1);
        } elseif (str_ends_with($stripped, '-')) {
            $sign = -1;
            $stripped = substr($stripped, 0, -1);
        }

        // ICS writes one notation and only one: comma decimal, period
        // thousands, and exactly as many fractional digits as the currency
        // has — a yen has none. Handing a looser figure to the shared parser
        // would read a "6,06" that lost its comma as six hundred euros.
        $decimals = CurrencyScale::decimals($currencyCode);
        $unsigned = str_replace('.', '', trim($stripped));
        $parts = explode(',', $unsigned);
        if (! self::shapeMatchesScale($parts, $decimals)) {
            throw new InvalidAmountException(sprintf('Invalid Dutch amount format: %s', $raw));
        }

        $minor = MoneyInput::tryToMinor($unsigned, $currencyCode);
        if ($minor === null) {
            throw new InvalidAmountException(sprintf('Amount out of range: %s', $raw));
        }

        return $sign * $minor;
    }

    /**
     * @param  list<string>  $parts  the figure split on its decimal comma
     */
    private static function shapeMatchesScale(array $parts, int $decimals): bool
    {
        if ($decimals === 0) {
            return count($parts) === 1 && ctype_digit($parts[0]);
        }

        return count($parts) === 2
            && ctype_digit($parts[0])
            && ctype_digit($parts[1])
            && strlen($parts[1]) === $decimals;
    }

    private static function currencyPattern(): string
    {
        return '/['.preg_quote(implode('', Money::SYMBOLS), '/').']'
            .'|\b(?:'.implode('|', self::ISO_CODES).')\b/u';
    }
}
