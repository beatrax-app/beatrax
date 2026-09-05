<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\SeededDisplayName;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;

// The corpus seeds a deduction category in the jurisdiction's language, so an
// English reader filing in the Netherlands was shown "Zorgkosten" with nothing
// to fall back from. `corpus_key` was already on the row and already read for
// seed idempotency; this is the one place that reads it for display.
/**
 * @link ../../../../.docs/features/tax/deduction-category-wording.md
 */
final class TaxCorpusWording
{
    /** @var array<string, array<string, array<string, string>>> country => key => field => wording */
    private static array $cache = [];

    // `name` is the only one of the three the reader can edit, so it is the only
    // one taking a provenance flag: a rename is the user's own words and stays
    // verbatim in every language. `short_name` and `hint` have no editor at all,
    // and a category the reader added carries no corpus key to resolve from.
    public static function name(?string $stored, ?string $country, ?string $key, mixed $nameIsDefault): ?string
    {
        return SeededDisplayName::prefer(
            self::wording($country, $key, 'name'),
            $stored,
            SeededDisplayName::isTrue($nameIsDefault),
        );
    }

    public static function shortName(?string $stored, ?string $country, ?string $key): ?string
    {
        return SeededDisplayName::prefer(self::wording($country, $key, 'short_name'), $stored);
    }

    public static function hint(?string $stored, ?string $country, ?string $key): ?string
    {
        return SeededDisplayName::prefer(self::wording($country, $key, 'hint'), $stored);
    }

    // The cache is per country and the bundled files are immutable, so only a
    // test has reason to drop it — one that must not read what another left.
    public static function forget(): void
    {
        self::$cache = [];
    }

    private static function wording(?string $country, ?string $key, string $field): ?string
    {
        if ($country === null || $country === '' || $key === null || $key === '') {
            return null;
        }

        $entries = self::$cache[$country] ??= self::readCorpus($country);
        $locale = Container::getInstance()->make(Translator::class)->getLocale();

        return $entries[$key][$locale.'.'.$field]
            ?? $entries[$key][Locale::DEFAULT.'.'.$field]
            ?? null;
    }

    /** @return array<string, array<string, string>> key => "<locale>.<field>" => wording */
    private static function readCorpus(string $country): array
    {
        $wording = [];
        foreach (Container::getInstance()->make(TaxCorpusLoader::class)->loadForCountry($country) as $entry) {
            $key = $entry['key'] ?? null;
            $blocks = $entry['i18n'] ?? null;
            if (! is_string($key) || ! is_array($blocks)) {
                continue;
            }

            $wording[$key] = self::flatten($blocks);
        }

        return $wording;
    }

    /**
     * @param  array<array-key, mixed>  $blocks
     * @return array<string, string>
     */
    private static function flatten(array $blocks): array
    {
        $flat = [];
        foreach ($blocks as $locale => $fields) {
            if (! is_string($locale) || ! is_array($fields)) {
                continue;
            }
            foreach ($fields as $field => $value) {
                if (is_string($field) && is_string($value) && $value !== '') {
                    $flat[$locale.'.'.$field] = $value;
                }
            }
        }

        return $flat;
    }
}
