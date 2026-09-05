<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// Three tables seed a display column with words and read it back: `categories`
// by slug, `currencies` by code, `tax_deduction_categories` by corpus key. This
// is the one rule all three obey — the reader's wording wins while the row is
// still the seeder's, and the row wins back once the user has written over it.
final class SeededDisplayName
{
    /**
     * @param  string  $group  The lang group and its trailing dot, e.g. `ledger::currencies.`
     * @param  bool  $stillTheSeeders  False once the user has written their own wording over it.
     */
    public static function fromLang(string $group, ?string $key, ?string $stored, bool $stillTheSeeders = true): ?string
    {
        if ($key === null || $key === '') {
            return $stored;
        }

        $line = $group.$key;
        $translated = Lang::get($line);

        return self::prefer($translated === $line ? null : $translated, $stored, $stillTheSeeders);
    }

    // Not every source is a lang group: the tax corpus is per-jurisdiction
    // bundled data carrying its own translations, and hands one in here.
    /**
     * @param  string|null  $candidate  What the reader's locale offers for this row, if anything.
     * @param  bool  $stillTheSeeders  False once the user has written their own wording over it.
     */
    public static function prefer(?string $candidate, ?string $stored, bool $stillTheSeeders = true): ?string
    {
        return $stillTheSeeders && $candidate !== null && $candidate !== '' ? $candidate : $stored;
    }

    // SQLite hands a boolean column back as 0 or 1, and as the string '1' on a
    // fetch that stringifies. Those three plus a real bool are every shape the
    // one shipped driver produces.
    public static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
