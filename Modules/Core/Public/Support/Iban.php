<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// An IBAN carries no break opportunity of its own, so a narrow column split it
// wherever the room ran out: "NL10BANK00005000" over "01". ISO 13616 groups it
// in fours, which is how a bank prints one and how the reveal row's placeholder
// is already drawn, and the spaces are somewhere a browser can break instead.
final class Iban
{
    public static function grouped(string $iban): string
    {
        $compact = str_replace(' ', '', $iban);

        return $compact === '' ? '' : implode(' ', str_split($compact, 4));
    }
}
