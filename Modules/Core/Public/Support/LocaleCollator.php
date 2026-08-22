<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Collator;
use Error;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;
use IntlException;
use Normalizer;

// The ordering seam for any list the reader scans by name. Byte comparison
// files every accented name after Z and knows no alphabet but ASCII's, so a
// Greek, Polish or Dutch reader gets an order that is not their own — and a
// picker with no search box is only as usable as its order.
final class LocaleCollator
{
    // Kept off the fold's own regex literal so the two spellings of "an
    // accent is not a letter" cannot drift: enclosing marks matter for the
    // Cyrillic and Greek names ICU would otherwise have ordered.
    private const string COMBINING_MARKS = '/[\p{Mn}\p{Me}]+/u';

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

    // Not iconv //TRANSLIT: its tables are the C library's, and this arm is the
    // one both phones take. macOS answered "Færge" with "ae?rge" and every
    // Greek name with the empty string, so those names sorted arbitrarily
    // among themselves — and differently again on Android.
    /**
     * @link ../../../../.docs/features/counterparties/slug-is-a-cross-platform-key.md
     */
    private static function fold(string $label): string
    {
        $decomposed = Normalizer::normalize($label, Normalizer::FORM_KD);
        $base = is_string($decomposed) ? $decomposed : $label;

        return Str::ascii(preg_replace(self::COMBINING_MARKS, '', $base) ?? $base);
    }
}
