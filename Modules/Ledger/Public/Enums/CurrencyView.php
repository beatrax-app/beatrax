<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Which amount the transactions list prints per row. These two values ARE
// what `users.default_currency_view` stores and what the `?currency=` query
// string carries, so renaming either silently resets every reader's choice.
enum CurrencyView: string
{
    case BaseOnly = 'eur_only';

    case Original = 'original';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
