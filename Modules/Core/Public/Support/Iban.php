<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// An IBAN carries no break opportunity of its own, so every narrow column that
// draws one split it wherever the room ran out: the import preview's funding
// column rendered NL10BANK0000500001 as "NL10BANK00005000" over "01", on all
// hundred rows. ISO 13616's presentation format groups it in fours -- which is
// how a bank prints it, and how the reveal row's own placeholder is already
// drawn -- and the spaces are somewhere the browser can break instead.
final class Iban
{
    public static function grouped(string $iban): string
    {
        $compact = str_replace(' ', '', $iban);

        return $compact === '' ? '' : implode(' ', str_split($compact, 4));
    }
}
