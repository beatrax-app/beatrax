<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// An IBAN carries no break opportunity of its own, so a narrow column split it
// wherever the room ran out: "NL10BANK00005000" over "01". ISO 13616 groups it
// in fours, which is how a bank prints one and how the reveal row's placeholder
// is already drawn, and the spaces are somewhere a browser can break instead.
final class Iban
{
    // ISO 13616 bounds: 15 characters is Norway, the shortest national format,
    // and 34 is the published global maximum. Mod-97 is deliberately not
    // checked, for the reason AccountNamer gives -- MT940 and CAMT extracts
    // arrive truncated, and a truncated IBAN still reads better in fours.
    private const string SHAPE = '/^[A-Z0-9]{15,34}$/i';

    public static function isIban(string $value): bool
    {
        return preg_match(self::SHAPE, str_replace(' ', '', $value)) === 1;
    }

    // A wallet export and a card statement carry no IBAN of the reader's own,
    // so their source writes a stand-in instead. Grouped like the real thing
    // it reached a device as "REVO LUT", so only an IBAN is grouped and a
    // stand-in comes back exactly as it arrived.
    /**
     * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-stand-in-for-an-iban-drawn-as-one
     */
    public static function grouped(string $iban): string
    {
        $compact = str_replace(' ', '', $iban);

        return self::isIban($compact) ? implode(' ', str_split($compact, 4)) : $iban;
    }
}
