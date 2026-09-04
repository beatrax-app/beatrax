<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Matchers;

use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// The body-shaping every sender matcher needs before it can read a receipt,
// held as a collaborator rather than a trait so no matcher's own promoted
// dependencies are read from outside its class body.
final class ReceiptBodyText
{
    public function plainText(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $stripped = strip_tags($decoded);
        $collapsed = PatternScan::replace('/[ \t]+/', ' ', $stripped);

        return trim($collapsed);
    }

    // The currency gates the parse and also scales it: told nothing, MoneyInput
    // assumes a hundredth, and a receipt reading "1250" for a yen charge landed
    // as ¥125,000 -- a hundred times the figure, matched against nothing.
    public function amountMinor(string $raw, string $currency): ?int
    {
        return Currency::tryFrom($currency) === null ? null : MoneyInput::tryToMinor($raw, $currency);
    }

    // Every mark a receipt names its money with, as one alternation, so an
    // anchor cannot recognise a currency the parse behind it will not read at.
    // Closed to the codes this app names: a bare [A-Z]{3} against a whole body
    // reads "Referentienummer: ABC123" as an amount.
    public static function currencyMarkers(): string
    {
        $marks = array_map(
            preg_quote(...),
            [...array_values(Money::SYMBOLS), ...array_column(Currency::cases(), 'value')],
        );

        return implode('|', $marks);
    }

    // What currencyMarkers() captured, back as an ISO code. A figure the
    // message marked with nothing keeps the denomination the format itself
    // settles in, which is the only currency left to name it with.
    public function currencyMarked(string $marker, string $fallback): string
    {
        $trimmed = trim($marker);
        if ($trimmed === '') {
            return $fallback;
        }

        return Money::codeForSymbol($trimmed)
            ?? Currency::tryFrom(strtoupper($trimmed))->value
            ?? $fallback;
    }
}
