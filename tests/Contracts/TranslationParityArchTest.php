<?php

declare(strict_types=1);

use Illuminate\Translation\MessageSelector;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;

// Parity is on key paths, not values: a translation intentionally identical to
// English (a proper noun, a symbol) still passes. Placeholders are checked too —
// a dropped :count or %s renders the token literally to the reader.

// Parity is agreement, which is not completeness. A key missing from all
// twenty-six passes here by construction; EveryKeyACallSiteNamesResolvesToALine
// and EveryTranslatedLineReachesAReader are the two rules that answer that.
// @link ../../.docs/conventions/a-call-site-names-a-key-that-resolves.md

/**
 * @param  array<array-key, mixed>  $translations
 * @return list<string>
 */
function translationParityKeyPaths(array $translations, string $prefix = ''): array
{
    $paths = [];
    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $paths = array_merge($paths, translationParityKeyPaths($value, $path));
        } else {
            $paths = array_merge($paths, [$path]);
        }
    }

    return $paths;
}

/**
 * @param  array<array-key, mixed>  $translations
 * @return array<string, string>
 */
function translationParityStrings(array $translations, string $prefix = ''): array
{
    $flat = [];
    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += translationParityStrings($value, $path);
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

// A few strings glue a unit abbreviation straight onto the token: ":nh" is
// ":n" plus an hours suffix, ":secondss" is ":seconds" plus one for seconds.
// The suffix is localisable and the token is not, so these keys are checked
// for the token alone. The map holds what the caller actually interpolates.
const TRANSLATION_PARITY_GLUED_UNIT_KEYS = [
    'Modules/EmailScan/Resources/lang/en/inboxes.php|retry_seconds' => ':n',
    'Modules/EmailScan/Resources/lang/en/inboxes.php|retry_minutes' => ':n',
    'Modules/EmailScan/Resources/lang/en/inboxes.php|retry_hours' => ':n',
    'Modules/Mobile/Resources/lang/en/lock.php|errors.too_many_attempts' => ':seconds',
    'Modules/DevMode/Resources/lang/en/sql.php|rows_meta' => ':duration',
    'Modules/Core/Resources/lang/en/alerts.php|messages.backup_overdue' => ':hours',
];

/** @return list<string> the placeholder tokens a translator must carry over, as a set */
function translationParityPlaceholders(string $line, ?string $gluedToken): array
{
    if ($gluedToken !== null) {
        return str_contains($line, $gluedToken) ? [$gluedToken] : [];
    }

    $matches = PatternScan::all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $line);
    $formats = PatternScan::all('/%[sd]/', $line);

    $tokens = array_merge($matches[0], $formats[0]);

    // A set, not a multiset: a locale needing three plural segments where
    // English needs two legitimately repeats :count an extra time.
    $tokens = array_values(array_unique($tokens));
    sort($tokens);

    return $tokens;
}

// A numeral glued to a currency sign is an amount, not a count: the "€0" in
// "Balance dips below €0 on :count days" reads €0 whether one day or five. Cut
// amounts before the numeral scan so it keeps its aim on a numeral standing in
// for :count — "1 payment of €5 due" still reports its leading "1".
function translationParityWithoutAmounts(string $segment): string
{
    return PatternScan::replace(
        '/\p{Sc}[\s\x{00A0}]*\d[\d.,]*|\d[\d.,]*[\s\x{00A0}]*\p{Sc}/u',
        '',
        $segment
    );
}

// How many `|` segments trans_choice can select in this locale, asked of
// Laravel's own rule table rather than restated here: English needs two and
// Polish three, so a two-segment Polish string silently renders the singular
// for every "many" count.
function translationParityPluralForms(string $locale): int
{
    $selector = new MessageSelector;
    $indexes = [];
    foreach (range(0, 200) as $number) {
        $indexes[$selector->getPluralIndex($locale, $number)] = true;
    }

    return max(array_keys($indexes)) + 1;
}

/** @return list<string> every supported locale, English included — the source mirrors itself */
function translationParityTargetLocales(): array
{
    return array_map(static fn (Locale $case): string => $case->value, Locale::cases());
}

/**
 * @param  list<string>  $locales
 * @return array<int, true> segment indexes any of these locales selects for a number other than one
 */
function translationParityUnsafeSegments(array $locales): array
{
    $selector = new MessageSelector;

    $unsafe = [];
    foreach ($locales as $locale) {
        foreach (range(2, 200) as $number) {
            $unsafe[$selector->getPluralIndex($locale, $number)] = true;
        }
    }

    return $unsafe;
}

/** @return list<string> absolute paths to the English lang files every locale mirrors */
function translationParitySourceFiles(): array
{
    $files = glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [];
    sort($files);

    return array_values($files);
}

it('ships every en translation key in every supported locale, per module lang file', function (): void {
    $enFiles = translationParitySourceFiles();
    expect($enFiles)->not->toBeEmpty();
    expect(translationParityTargetLocales())->not->toBeEmpty();

    $problems = [];
    foreach (translationParityTargetLocales() as $locale) {
        foreach ($enFiles as $enFile) {
            $targetFile = str_replace('/lang/en/', '/lang/'.$locale.'/', $enFile);
            $rel = str_replace(base_path().'/', '', $enFile);

            if (! is_file($targetFile)) {
                $problems[] = $rel.': no '.$locale.' counterpart file';

                continue;
            }

            $en = require $enFile;
            $translated = require $targetFile;
            if (! is_array($en) || ! is_array($translated)) {
                $problems[] = $rel.': a lang file did not return an array';

                continue;
            }

            $enKeys = translationParityKeyPaths($en);
            $targetKeys = translationParityKeyPaths($translated);
            sort($enKeys);
            sort($targetKeys);

            $missing = array_values(array_diff($enKeys, $targetKeys));
            $stale = array_values(array_diff($targetKeys, $enKeys));
            if ($missing !== []) {
                $problems[] = $rel.': '.$locale.' is missing ['.implode(', ', $missing).']';
            }
            if ($stale !== []) {
                $problems[] = $rel.': '.$locale.' has stale ['.implode(', ', $stale).']';
            }
        }
    }

    expect($problems)->toBe([], "translation parity broken:\n  ".implode("\n  ", $problems));
});

it('carries every en placeholder into every supported locale', function (): void {
    $problems = [];
    foreach (translationParityTargetLocales() as $locale) {
        foreach (translationParitySourceFiles() as $enFile) {
            $targetFile = str_replace('/lang/en/', '/lang/'.$locale.'/', $enFile);
            if (! is_file($targetFile)) {
                continue;
            }

            $en = require $enFile;
            $translated = require $targetFile;
            if (! is_array($en) || ! is_array($translated)) {
                continue;
            }

            $rel = str_replace(base_path().'/', '', $enFile);
            $translatedStrings = translationParityStrings($translated);
            foreach (translationParityStrings($en) as $path => $line) {
                if (! array_key_exists($path, $translatedStrings)) {
                    continue;
                }

                $glued = TRANSLATION_PARITY_GLUED_UNIT_KEYS[$rel.'|'.$path] ?? null;
                $expected = translationParityPlaceholders($line, $glued);
                $actual = translationParityPlaceholders($translatedStrings[$path], $glued);

                // Containment, not equality. Dropping an en token renders the
                // literal ":count" to the reader; an extra token cannot be
                // interpolated, and some languages punctuate with a colon —
                // Finnish writes "HTTP:tä" and "10 Mt:n".
                $dropped = array_values(array_diff($expected, $actual));
                if ($dropped !== []) {
                    $problems[] = $rel.' ['.$path.'] '.$locale.': dropped ['
                        .implode(', ', $dropped).'] from ['.implode(', ', $expected).']';
                }
            }
        }
    }

    expect($problems)->toBe([], "translation placeholders broken:\n  ".implode("\n  ", $problems));
});

/**
 * Whether a segment carries an explicit {0} or [2,*] range. MessageSelector
 * matches those against the number BEFORE the rule table is consulted, and
 * the regex is its own so the two spellings of "a range" cannot drift.
 */
function translationParityCarriesRange(string $segment): bool
{
    return preg_match('/^[\{\[]([-?\d|*,\.*]*)[\}\]](.*)/s', $segment, $matches) === 1
        && count($matches) === 3;
}

// A floor and a ceiling. A segment past the count the locale selects between
// is text no number can reach: trans_choice asks the rule table for an index
// and returns that segment, so a second Turkish form renders for no count at
// all and reads to its author as though it shipped.
it('gives every pluralised string as many segments as the locale selects between', function (): void {
    $problems = [];
    foreach (translationParityTargetLocales() as $locale) {
        $forms = translationParityPluralForms($locale);
        foreach (translationParitySourceFiles() as $enFile) {
            $targetFile = str_replace('/lang/en/', '/lang/'.$locale.'/', $enFile);
            if (! is_file($targetFile)) {
                continue;
            }

            $en = require $enFile;
            $translated = require $targetFile;
            if (! is_array($en) || ! is_array($translated)) {
                continue;
            }

            $rel = str_replace(base_path().'/', '', $enFile);
            $translatedStrings = translationParityStrings($translated);
            foreach (translationParityStrings($en) as $path => $line) {
                if (! str_contains($line, '|') || ! array_key_exists($path, $translatedStrings)) {
                    continue;
                }

                $segments = explode('|', $translatedStrings[$path]);
                $actual = count($segments);
                if ($actual < $forms) {
                    $problems[] = $rel.' ['.$path.'] '.$locale.': needs '.$forms.' segments, has '.$actual;
                }

                // stripConditions() keeps the whole list and the rule index
                // addresses it, so a segment past the count the table selects
                // between is reachable only through a range of its own.
                foreach ($segments as $index => $segment) {
                    if ($index >= $forms && ! translationParityCarriesRange($segment)) {
                        $problems[] = $rel.' ['.$path.'] '.$locale.': segment '
                            .($index + 1).' is past the '.$forms.' the rule table selects between, and carries no range';
                    }
                }
            }
        }
    }

    expect($problems)->toBe([], "plural segments missing:\n  ".implode("\n  ", $problems));
});

it('never hard-codes a numeral into a plural form that also selects other numbers', function (): void {
    $problems = [];
    foreach (translationParityTargetLocales() as $locale) {
        // A locale answers to its own rule table: Croatian's first form covers
        // 21, 31 and 101, so a literal "1" there renders "1 mjesec" beside 21.
        // English answers to all of them, because the en line is the shape every
        // translation is written against and a literal in it gets copied across.
        $unsafe = $locale === Locale::DEFAULT
            ? translationParityUnsafeSegments(translationParityTargetLocales())
            : translationParityUnsafeSegments([$locale]);

        foreach (translationParitySourceFiles() as $enFile) {
            $targetFile = str_replace('/lang/en/', '/lang/'.$locale.'/', $enFile);
            if (! is_file($targetFile)) {
                continue;
            }

            $translated = require $targetFile;
            if (! is_array($translated)) {
                continue;
            }

            $rel = str_replace(base_path().'/', '', $enFile);
            foreach (translationParityStrings($translated) as $path => $line) {
                if (! str_contains($line, '|')) {
                    continue;
                }

                foreach (explode('|', $line) as $index => $segment) {
                    // An explicit {1} or [2,*] range is matched exactly, so a
                    // literal inside it is pinned to the numbers it names.
                    if (preg_match('/^\s*[\{\[]/', $segment) === 1) {
                        continue;
                    }
                    if (! array_key_exists($index, $unsafe)) {
                        continue;
                    }
                    if (preg_match('/(?<![:\w])\d+/', translationParityWithoutAmounts($segment)) === 1) {
                        $reason = $locale === Locale::DEFAULT
                            ? 'is the line every locale mirrors'
                            : 'also selects other counts';
                        $problems[] = $rel.' ['.$path.'] '.$locale
                            .': segment '.$index.' hard-codes a numeral but '.$reason.' — "'.trim($segment).'"';
                    }
                }
            }
        }
    }

    expect($problems)->toBe([], "hard-coded numerals in shared plural forms:\n  ".implode("\n  ", $problems));
});

it('has no translation file without an en counterpart', function (): void {
    $orphans = [];
    foreach (translationParityTargetLocales() as $locale) {
        foreach (glob(base_path('Modules/*/Resources/lang/'.$locale.'/*.php')) ?: [] as $file) {
            $enFile = str_replace('/lang/'.$locale.'/', '/lang/en/', $file);
            if (! is_file($enFile)) {
                $orphans[] = str_replace(base_path().'/', '', $file);
            }
        }
    }

    expect($orphans)->toBe([]);
});

it('declares every locale whose translation is finished', function (): void {
    $supported = array_map(static fn (Locale $case): string => $case->value, Locale::cases());
    $enFiles = translationParitySourceFiles();

    $candidates = [];
    foreach (glob(base_path('Modules/*/Resources/lang/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $candidates[basename($dir)] = true;
    }

    // A part-translated directory is work in progress and is inert — nothing
    // can select a locale the enum does not name. A complete one that is still
    // undeclared is finished work that never reaches a reader, so it fails.
    $finishedButUndeclared = [];
    foreach (array_keys($candidates) as $locale) {
        if (in_array($locale, $supported, true)) {
            continue;
        }

        $translated = 0;
        foreach ($enFiles as $enFile) {
            if (is_file(str_replace('/lang/en/', '/lang/'.$locale.'/', $enFile))) {
                $translated++;
            }
        }
        if ($translated === count($enFiles)) {
            $finishedButUndeclared[] = $locale;
        }
    }

    expect($finishedButUndeclared)->toBe(
        [],
        'complete but undeclared locales: '.implode(', ', $finishedButUndeclared)
    );
});
