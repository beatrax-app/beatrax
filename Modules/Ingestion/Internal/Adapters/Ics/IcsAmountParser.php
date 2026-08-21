<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class IcsAmountParser
{
    // The currency list is closed rather than \b[A-Z]{3}\b, which would
    // swallow any three-letter token sitting beside the amount. The glyphs
    // come from Money so this does not become a second list of them.
    private const array ISO_CODES = ['EUR', 'USD', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD'];

    public function parse(string $raw): int
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

        // ICS writes one convention and only one: comma decimal, period
        // thousands, always two fractional digits. Handing a looser figure to
        // the shared parser would read a "6,06" that lost its comma as six
        // hundred euros instead of refusing the row.
        $unsigned = str_replace('.', '', trim($stripped));
        $parts = explode(',', $unsigned);
        if (
            count($parts) !== 2
            || ! ctype_digit($parts[0])
            || ! ctype_digit($parts[1])
            || strlen($parts[1]) !== 2
        ) {
            throw new InvalidAmountException(sprintf('Invalid Dutch amount format: %s', $raw));
        }

        $minor = MoneyInput::tryToMinor($unsigned);
        if ($minor === null) {
            throw new InvalidAmountException(sprintf('Amount out of range: %s', $raw));
        }

        return $sign * $minor;
    }

    private static function currencyPattern(): string
    {
        return '/['.preg_quote(implode('', Money::SYMBOLS), '/').']'
            .'|\b(?:'.implode('|', self::ISO_CODES).')\b/u';
    }
}
