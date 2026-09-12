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
    // Every block and cell boundary becomes a break before the tags go:
    // strip_tags() joins what the markup kept apart, and a minified receipt
    // table read back as "AMAZON.COMBedrag:€ 46,20Referentienummer:XYZ123" —
    // one line, so no label is in front of the figure it names any more.
    private const string BLOCK_BOUNDARY_REGEX = '/<br\s*\/?>|<\/(?:p|div|td|th|tr|table|tbody|thead|li|ul|ol|h[1-6]|blockquote)\s*>/i';

    public function plainText(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $broken = PatternScan::replace(self::BLOCK_BOUNDARY_REGEX, "\n", $decoded);
        $stripped = strip_tags($broken);
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

    // A marked figure, as both unlabelled anchors search for it. The lookbehind
    // is the whole of it: a mark run together with what precedes it is part of
    // a word, not a denomination. "Referentienummer: ABCEUR123456" was read as
    // EUR 123.456,00, and a transaction id ending USD00001 as USD 1.00.
    public static function markedAmount(): string
    {
        return '(?<![0-9A-Za-z])('.self::currencyMarkers().')\s*([0-9][0-9.,]*)';
    }

    // A figure is the receipt's total only where its sender labelled it one.
    // Matched against a whole body, every anchor took the first denominated
    // figure in it, so a subtotal, a tax line, a card limit or a wallet balance
    // standing above the total was the figure booked.
    /**
     * @link ../../../../.docs/features/receipts/architecture.md#a-total-is-the-figure-its-sender-labelled
     */
    public static function underLabel(string $labels, string $figure): string
    {
        // The lookbehind is the label's own: "Subtotaal" is not "Totaal" and
        // "Subtotal" is not "Total", and booking either charges the reader the
        // pre-tax figure. A longer label a sender does spell — PayPal's
        // "Transactiebedrag" — is listed rather than reached by substring.
        return '(?<![A-Za-z])(?:'.$labels.')\s*:\s*'.$figure;
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
