<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Modules\Core\Public\Support\Lang;

// Both currency pickers label their options through here. The code is the row's
// own primary key and the one part of it no language touches, so a code the
// transcript does not carry reads as itself rather than in somebody else's
// language — which is what a stored English `name` column used to answer.
final class CurrencyDisplayName
{
    public static function forCode(string $code): string
    {
        return CurrencyNames::forLocale(Lang::locale())[$code] ?? $code;
    }
}
