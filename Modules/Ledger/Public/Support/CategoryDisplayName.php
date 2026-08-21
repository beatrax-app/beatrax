<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use InvalidArgumentException;
use Modules\Core\Public\Support\Lang;
use stdClass;

// `categories.name` does one job now: it holds the app's own English wording
// for a row nobody has renamed, and the user's own wording for a row they
// have. `name_is_default` says which, and this is the one place that asks.
final class CategoryDisplayName
{
    private const string KEY_PREFIX = 'categorization::categories.';

    private const array PARTS = ['name', 'slug', 'name_is_default'];

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
    // straight off `categories` and needs no disambiguating prefix. Any other
    // value has to be the one its columns() call was given.
    /**
     * @throws InvalidArgumentException when the row carries no such columns.
     */
    public static function fromRow(stdClass $row, string $alias = ''): ?string
    {
        $values = self::aliasedValues($row, $alias);

        $name = $values['name'];
        if (! is_string($name)) {
            return null;
        }

        $slug = $values['slug'];

        return self::resolve(
            $name,
            is_string($slug) ? $slug : '',
            self::isTrue($values['name_is_default']),
        );
    }

    // The flag on its own, for a caller carrying the provenance somewhere else
    // rather than rendering it here — a notification resolved in the reader's
    // language rather than the queue worker's.
    /**
     * @throws InvalidArgumentException when the row carries no such columns.
     */
    public static function isDefaultRow(stdClass $row, string $alias = ''): bool
    {
        return self::isTrue(self::aliasedValues($row, $alias)['name_is_default']);
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

    // A missing key and a NULL value are different answers: NULL is a row with
    // no category joined, an absent key is a read site whose alias does not
    // match the one its columns() call selected under. That mismatch used to
    // return null and print a blank cell.
    /**
     * @return array{name: mixed, slug: mixed, name_is_default: mixed}
     *
     * @throws InvalidArgumentException
     */
    private static function aliasedValues(stdClass $row, string $alias): array
    {
        $prefix = $alias === '' ? '' : $alias.'_';
        $values = get_object_vars($row);

        $missing = array_values(array_filter(
            self::PARTS,
            static fn (string $part): bool => ! array_key_exists($prefix.$part, $values),
        ));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'CategoryDisplayName: the row carries no %s. Pass columns() and fromRow() the same alias.',
                implode(', no ', array_map(static fn (string $part): string => $prefix.$part, $missing)),
            ));
        }

        return [
            'name' => $values[$prefix.'name'],
            'slug' => $values[$prefix.'slug'],
            'name_is_default' => $values[$prefix.'name_is_default'],
        ];
    }

    // SQLite hands the flag back as 0 or 1, and as the string '1' on a fetch
    // that stringifies. Those three plus a real bool are every shape the one
    // shipped driver produces — 't', 'true' and 1.0 are not among them.
    private static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
