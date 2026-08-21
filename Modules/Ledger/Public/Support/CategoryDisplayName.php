<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Modules\Core\Public\Support\Lang;

// `categories.name` does one job now: it holds the app's own English wording
// for a row nobody has renamed, and the user's own wording for a row they
// have. `name_is_default` says which, and this is the one place that asks.
final class CategoryDisplayName
{
    private const KEY_PREFIX = 'categorization::categories.';

    // The three columns a read site has to carry for fromRow() to answer.
    /**
     * @return list<string>
     */
    public static function columns(string $table, string $alias = 'category'): array
    {
        return [
            $table.'.name as '.$alias.'_name',
            $table.'.slug as '.$alias.'_slug',
            $table.'.name_is_default as '.$alias.'_name_is_default',
        ];
    }

    // An empty $alias reads the bare column names, for a query that selects
    // straight off `categories` and needs no disambiguating prefix.
    public static function fromRow(object $row, string $alias = ''): ?string
    {
        $prefix = $alias === '' ? '' : $alias.'_';
        $values = get_object_vars($row);

        $name = $values[$prefix.'name'] ?? null;
        if (! is_string($name)) {
            return null;
        }

        $slug = $values[$prefix.'slug'] ?? null;

        return self::resolve(
            $name,
            is_string($slug) ? $slug : '',
            self::isTrue($values[$prefix.'name_is_default'] ?? null),
        );
    }

    // A rename is the user's own words and stays verbatim in every language.
    // An untouched default re-resolves from its slug, so it follows the
    // reader. A slug with no translation keeps the English already stored,
    // which is what the seeder wrote rather than a key on a budget screen.
    public static function resolve(string $storedName, string $slug, bool $nameIsDefault): string
    {
        if (! $nameIsDefault || $slug === '') {
            return $storedName;
        }

        $key = self::KEY_PREFIX.$slug;
        $translated = Lang::get($key);

        return $translated === $key ? $storedName : $translated;
    }

    // SQLite hands a boolean back as 0/1, and as a string on drivers that
    // stringify fetches.
    private static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
