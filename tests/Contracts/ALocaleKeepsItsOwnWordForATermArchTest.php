<?php

declare(strict_types=1);

// Dutch had settled on "winkelier" -- Anomaly, Counterparties and Core settings
// all use it, and Core settings headed the shared list "Gedeelde winkelierslijst".
// The Community page headed the same list "Gedeelde merchantlijst" and asked the
// reader to "Help merchants herkennen". Every one of the other 25 locales
// translated the word; Dutch was the only one that did not, and parity could not
// see it because the key was present and the value non-empty.

/**
 * The English source word, then the word the locale already uses for it in
 * production strings. Only terms a locale has genuinely settled: a term still
 * spelled both ways on purpose would flag its own deliberate usage.
 *
 * @return array<string, array<string, string>>
 */
function settledTerms(): array
{
    return [
        'nl' => ['merchant' => 'winkelier'],
    ];
}

/**
 * @return array<string, string>
 */
function flattenedStrings(string $file): array
{
    /** @var array<array-key, mixed> $loaded */
    $loaded = require $file;

    $walk = static function (array $node, string $prefix) use (&$walk): array {
        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $walk($value, $path);

                continue;
            }
            $out[$path] = (string) $value;
        }

        return $out;
    };

    return $walk($loaded, '');
}

it('does not fall back to the English word for a term the locale already translates', function (): void {
    $leaks = [];

    foreach (settledTerms() as $locale => $terms) {
        foreach (glob(base_path("Modules/*/Resources/lang/{$locale}/*.php")) ?: [] as $file) {
            foreach (flattenedStrings($file) as $key => $value) {
                foreach ($terms as $english => $native) {
                    if (preg_match('/\b'.$english.'/iu', $value) !== 1) {
                        continue;
                    }

                    $leaks[] = str_replace(base_path().'/', '', $file)." [{$key}] "
                        ."said \"{$english}\" where {$locale} says \"{$native}\": {$value}";
                }
            }
        }
    }

    sort($leaks);

    expect($leaks)->toBe(
        [],
        "These translated strings use the English term the locale has its own word for:\n  "
        .implode("\n  ", $leaks)
    );
});

// The rule above reads the translation and looks for English. It cannot see a
// locale answering with a SECOND word of its own: the sidebar described the
// shared list as "kennis over verkopers" and the at-rest disclosure named
// "namen van winkels", while 23 other strings translating the same English
// word said winkelier. Three Dutch words, one English term, and every one of
// them real Dutch — so neither parity nor the English-leak rule could object.
//
// This reads the SOURCE file and requires the settled word in the answer.

it('answers an English term with the one word the locale settled on, not a second', function (): void {
    $offenders = [];
    $compared = 0;

    foreach (settledTerms() as $locale => $terms) {
        foreach (glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [] as $source) {
            $translated = str_replace('/lang/en/', "/lang/{$locale}/", $source);

            if (! is_file($translated)) {
                continue;
            }

            $localised = flattenedStrings($translated);

            foreach (flattenedStrings($source) as $key => $english) {
                foreach ($terms as $term => $native) {
                    if (preg_match('/\\b'.$term.'s?\\b/iu', $english) !== 1) {
                        continue;
                    }

                    $value = $localised[$key] ?? null;

                    if ($value === null) {
                        continue;
                    }

                    $compared++;

                    if (preg_match('/'.$native.'/iu', $value) === 1) {
                        continue;
                    }

                    $offenders[] = str_replace(base_path().'/', '', $translated)." [{$key}] "
                        ."answers \"{$term}\" without saying \"{$native}\": {$value}";
                }
            }
        }
    }

    // A walk that compared nothing would pass while proving nothing.
    expect($compared)->toBeGreaterThan(15);

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "One English term, more than one word for it in this locale:\n  "
        .implode("\n  ", $offenders)
    );
});
