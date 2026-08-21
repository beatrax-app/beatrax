<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Collator;
use Error;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use IntlException;

// The ordering seam for any list the reader scans by name. Byte comparison
// files every accented name after Z and knows no alphabet but ASCII's, so a
// Greek, Polish or Dutch reader gets an order that is not their own — and a
// picker with no search box is only as usable as its order.
final class LocaleCollator
{
    /**
     * @var array<string, Collator|null>
     */
    private static array $collators = [];

    public static function compare(string $a, string $b): int
    {
        $collator = self::collator();

        if (! $collator instanceof Collator) {
            return strnatcasecmp(self::fold($a), self::fold($b));
        }

        $order = $collator->compare($a, $b);

        // compare() answers false on a collation failure; treating that as
        // "equal" keeps the sort stable rather than ordering on junk.
        return $order === false ? 0 : $order;
    }

    // Memoised per locale because building an ICU collator is far dearer than
    // a comparison and a sort asks for one n·log n times. The mobile build's
    // ext-intl carries English-only ICU data, so every other locale throws on
    // device; Error, because no ext-intl at all raises "Collator not found".
    private static function collator(): ?Collator
    {
        $locale = Container::getInstance()->make(Translator::class)->getLocale();

        if (! array_key_exists($locale, self::$collators)) {
            try {
                $collator = Collator::create($locale);
                // Digits read as numbers, not characters, so "Trip 10" follows
                // "Trip 2" — what two of the comparators replaced here already
                // did through strnatcasecmp, and what a reader expects anyway.
                $collator?->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
                self::$collators[$locale] = $collator;
            } catch (IntlException|Error) {
                self::$collators[$locale] = null;
            }
        }

        return self::$collators[$locale];
    }

    // Without ICU, fold the accents onto their base letters so a name at least
    // lands beside its own initial rather than after every unaccented one.
    private static function fold(string $label): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);

        return $folded === false ? $label : $folded;
    }
}
