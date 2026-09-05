<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Modules\Core\Public\Support\SeededDisplayName;

// `currencies.name` is seeded in English — "Pound Sterling" — and both currency
// pickers render that column straight as their option labels, so a Dutch reader
// adding an account was offered an English list. The code is the row's own
// primary key and the one part of it no translation touches.
final class CurrencyDisplayName
{
    private const string KEY_PREFIX = 'ledger::currencies.';

    // No provenance flag, unlike the two sibling tables: nothing in the app
    // writes this column after the seed, and the seed migration deliberately
    // restores the canonical name over an edited row, so a stored value is
    // always the seeder's.
    public static function forCode(string $code, ?string $stored): string
    {
        return SeededDisplayName::fromLang(self::KEY_PREFIX, strtolower($code), $stored) ?? $code;
    }
}
