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

/** Whether a translated line still uses the English term the locale has a word of its own for. */
function settledTermSaysEnglish(string $value, string $english): bool
{
    return preg_match('/\b'.$english.'/iu', $value) === 1;
}

/** Whether the English source line uses the term at all, singular or plural. */
function settledTermIsInSource(string $english, string $term): bool
{
    return preg_match('/\b'.$term.'s?\b/iu', $english) === 1;
}

/** Whether the locale's answer uses the one word it settled on. */
function settledTermAnswersWith(string $value, string $native): bool
{
    return preg_match('/'.$native.'/iu', $value) === 1;
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
    $read = 0;

    foreach (settledTerms() as $locale => $terms) {
        foreach (glob(base_path("Modules/*/Resources/lang/{$locale}/*.php")) ?: [] as $file) {
            foreach (flattenedStrings($file) as $key => $value) {
                $read++;

                foreach ($terms as $english => $native) {
                    if (! settledTermSaysEnglish($value, $english)) {
                        continue;
                    }

                    $leaks[] = str_replace(base_path().'/', '', $file)." [{$key}] "
                        ."said \"{$english}\" where {$locale} says \"{$native}\": {$value}";
                }
            }
        }
    }

    sort($leaks);

    // A locale ships thousands of lines. A glob that answered nothing would
    // report every one of them as translated.
    expect($read)->toBeGreaterThan(500, 'Read '.$read.' translated lines, too few for an empty offender list to mean anything.');

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
                    if (! settledTermIsInSource($english, $term)) {
                        continue;
                    }

                    $value = $localised[$key] ?? null;

                    if ($value === null) {
                        continue;
                    }

                    $compared++;

                    if (settledTermAnswersWith($value, $native)) {
                        continue;
                    }

                    $offenders[] = str_replace(base_path().'/', '', $translated)." [{$key}] "
                        ."answers \"{$term}\" without saying \"{$native}\": {$value}";
                }
            }
        }
    }

    // A walk that compared nothing would pass while proving nothing.
    expect($compared)->toBeGreaterThan(15, 'Compared '.$compared.' translated answers against their English source, too few to have read the pairs.');

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "One English term, more than one word for it in this locale:\n  "
        .implode("\n  ", $offenders)
    );
});

it('reads the English word left in a translation, and the second native word beside the settled one', function (): void {
    expect(settledTermSaysEnglish('Gedeelde merchantlijst', 'merchant'))
        ->toBeTrue('the compound is the shape it shipped as, and a word boundary in front of it is all this can ask');

    expect(settledTermSaysEnglish('Gedeelde winkelierslijst', 'merchant'))
        ->toBeFalse('the translated line is what every other locale writes');

    expect(settledTermIsInSource('Shared merchant list', 'merchant'))
        ->toBeTrue('the source line is what says the term is in play at all');

    expect(settledTermIsInSource('Shared merchants list', 'merchant'))
        ->toBeTrue('the plural is the same term, and reading only the singular missed half the pairs');

    expect(settledTermIsInSource('Shared counterparty list', 'merchant'))
        ->toBeFalse('a line that never names the term is not one this rule compares');

    expect(settledTermAnswersWith('kennis over verkopers', 'winkelier'))
        ->toBeFalse('a second Dutch word for the same term is real Dutch, in perfect parity, and still the defect');

    expect(settledTermAnswersWith('Gedeelde winkelierslijst', 'winkelier'))
        ->toBeTrue('the settled word answers the term, which is the whole rule');
});
