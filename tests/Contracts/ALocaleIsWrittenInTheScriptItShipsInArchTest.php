<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;

// A locale ships in one script, and the enum is where that is decided: the
// comment on Locale::Sr says Serbian ships in Latin, and twenty-six lines
// across ten sr files were Cyrillic anyway — one changing script mid-sentence.
// @link ../../.docs/conventions/translations-awaiting-a-native-reader.md#the-script-a-locale-ships-in

const LOCALE_SCRIPT_LATIN = 'Latin';

// Every case in the enum, and the script that locale's copy is written in.
// Latin is a decision here rather than the absence of one — Serbian could
// have shipped in either, and Locale::Sr records why it is this one. A case
// added to the enum with no line here fails the first rule below.
const LOCALE_SCRIPT_SHIPPED = [
    'cs' => LOCALE_SCRIPT_LATIN,
    'da' => LOCALE_SCRIPT_LATIN,
    'de' => LOCALE_SCRIPT_LATIN,
    'et' => LOCALE_SCRIPT_LATIN,
    'en' => LOCALE_SCRIPT_LATIN,
    'es' => LOCALE_SCRIPT_LATIN,
    'fr' => LOCALE_SCRIPT_LATIN,
    'hr' => LOCALE_SCRIPT_LATIN,
    'it' => LOCALE_SCRIPT_LATIN,
    'lv' => LOCALE_SCRIPT_LATIN,
    'lt' => LOCALE_SCRIPT_LATIN,
    'hu' => LOCALE_SCRIPT_LATIN,
    'nl' => LOCALE_SCRIPT_LATIN,
    'nb' => LOCALE_SCRIPT_LATIN,
    'pl' => LOCALE_SCRIPT_LATIN,
    'pt' => LOCALE_SCRIPT_LATIN,
    'ro' => LOCALE_SCRIPT_LATIN,
    'sk' => LOCALE_SCRIPT_LATIN,
    'sl' => LOCALE_SCRIPT_LATIN,
    'sr' => LOCALE_SCRIPT_LATIN,
    'fi' => LOCALE_SCRIPT_LATIN,
    'sv' => LOCALE_SCRIPT_LATIN,
    'tr' => LOCALE_SCRIPT_LATIN,
    'el' => 'Greek',
    'bg' => 'Cyrillic',
    'uk' => 'Cyrillic',
];

// The scripts this rule can tell apart, each matched as a LETTER of that
// script. The lookahead is load-bearing: PCRE reads \p{Greek} through script
// extensions, so the "·" this app separates a page title from its brand with
// answers to it, and a bare \p{Greek} reported 1,189 offences on a clean tree.
// Latin has no entry on purpose — it is legal in every locale, so nothing is
// ever checked against it and the rule stays one-directional.
const LOCALE_SCRIPT_PATTERNS = [
    'Cyrillic' => '/(?=\p{L})\p{Cyrillic}/u',
    'Greek' => '/(?=\p{L})\p{Greek}/u',
];

// Each entry names a file in a non-Latin locale that carries none of that
// locale's script, and why that is not a translation left in English. The
// `proves` pattern is re-run against the file: when it stops matching, the pin
// has outlived what earned it and the rules below fail rather than wave it on.
const LOCALE_SCRIPT_PINS = [
    'Modules/Search/Resources/lang/el/messages.php' => [
        'reason' => 'the file holds no copy at all — Search ships this lang file empty in every locale, English included, and an empty array is in no script',
        'proves' => '/return\s*\[\s*\];/',
    ],
    'Modules/Search/Resources/lang/bg/messages.php' => [
        'reason' => 'the file holds no copy at all — Search ships this lang file empty in every locale, English included, and an empty array is in no script',
        'proves' => '/return\s*\[\s*\];/',
    ],
    'Modules/Search/Resources/lang/uk/messages.php' => [
        'reason' => 'the file holds no copy at all — Search ships this lang file empty in every locale, English included, and an empty array is in no script',
        'proves' => '/return\s*\[\s*\];/',
    ],
];

/** @return list<string> absolute paths to every lang file this locale ships, module and framework alike */
function localeScriptFiles(string $locale): array
{
    $files = array_merge(
        glob(base_path('Modules/*/Resources/lang/'.$locale.'/*.php')) ?: [],
        glob(base_path('lang/'.$locale.'/*.php')) ?: [],
    );
    sort($files);

    return array_values($files);
}

/** @return list<string> the distinct letters of $script appearing in $text */
function localeScriptLettersIn(string $script, string $text): array
{
    $matches = PatternScan::all(LOCALE_SCRIPT_PATTERNS[$script], $text);

    return array_values(array_unique($matches[0]));
}

/** @return list<string> the scripts a file in this locale may not be written in */
function localeScriptForbiddenIn(string $locale): array
{
    return array_values(array_diff(array_keys(LOCALE_SCRIPT_PATTERNS), [LOCALE_SCRIPT_SHIPPED[$locale]]));
}

/** @return list<string> `path:line — script letters` for every line of $path written in $script */
function localeScriptOffendingLines(string $path, string $script): array
{
    $relative = str_replace(base_path().'/', '', $path);

    $offenders = [];
    foreach (file($path) ?: [] as $number => $line) {
        $letters = localeScriptLettersIn($script, $line);
        if ($letters !== []) {
            $offenders[] = $relative.':'.($number + 1).' — '.$script.' '.implode(' ', $letters);
        }
    }

    return $offenders;
}

it('declares the script every locale in the enum is written in', function (): void {
    $declared = array_keys(LOCALE_SCRIPT_SHIPPED);
    $shipped = array_map(static fn (Locale $case): string => $case->value, Locale::cases());
    sort($declared);
    sort($shipped);

    expect($declared)->toBe($shipped, implode("\n", [
        'LOCALE_SCRIPT_SHIPPED and Locale::cases() name different locales.',
        '',
        'The map is the expectation every rule in this file is derived from, so a',
        'locale the app ships and the map has never heard of is a locale nothing',
        'checks. Declaring the script is the whole decision: give the new case its',
        'line here, and say Latin only where Latin is what that language uses.',
    ]));

    $unknown = array_values(array_diff(
        array_values(array_unique(array_values(LOCALE_SCRIPT_SHIPPED))),
        array_merge([LOCALE_SCRIPT_LATIN], array_keys(LOCALE_SCRIPT_PATTERNS)),
    ));

    expect($unknown)->toBe([], implode("\n", [
        'These declared scripts have no pattern to recognise them by: '.implode(', ', $unknown),
        '',
        'A script named in the map and missing from LOCALE_SCRIPT_PATTERNS is a',
        'locale that silently checks nothing, which is worse than not declaring it.',
    ]));
});

it('writes every locale in the script that locale ships in', function (): void {
    $offenders = [];
    $files = 0;
    $bytes = 0;
    $perLocale = [];

    foreach (LOCALE_SCRIPT_SHIPPED as $locale => $script) {
        $perLocale[$locale] = 0;

        foreach (localeScriptFiles($locale) as $path) {
            $files++;
            $perLocale[$locale]++;
            $text = (string) file_get_contents($path);
            $bytes += strlen($text);

            foreach (localeScriptForbiddenIn($locale) as $intruder) {
                if (localeScriptLettersIn($intruder, $text) === []) {
                    continue;
                }

                $offenders = array_merge($offenders, localeScriptOffendingLines($path, $intruder));
            }
        }
    }

    // A glob answering nothing, a locale directory renamed, a pattern that
    // stopped compiling: each of them reports a clean tree from a walk that
    // read nothing at all. The floors are what makes that silence audible,
    // and the per-locale one catches a single locale dropping out.
    expect($files)->toBeGreaterThan(2000);
    expect($bytes)->toBeGreaterThan(4000000);
    expect(min($perLocale))->toBeGreaterThan(100, 'A shipped locale contributed almost no files: '.json_encode($perLocale));

    expect($offenders)->toBe([], implode("\n", [
        'These lang lines are written in a script their locale does not ship in:',
        ...$offenders,
        '',
        'Serbian is the case this rule was written for. It ships in Latin — the',
        'enum says so on Locale::Sr, because Cyrillic renders without a font',
        'fallback on every desktop and mobile target and Latin is what Serbian',
        'banking software overwhelmingly uses — and twenty-six lines across ten',
        'files were Cyrillic anyway. One of them, core::backup.intro_html, was',
        'Latin for the sentence and Cyrillic for its last clause.',
        '',
        'TranslationParityArchTest cannot see this and never will. It compares key',
        'paths, placeholder tokens and plural-segment counts between locales, and a',
        'line in the wrong script has exactly the right keys, the right :count and',
        'the right number of | segments. It is in perfect parity and still wrong on',
        'screen: the reader gets a page where most words are Latin and a handful',
        'are not, which reads as a rendering fault rather than as a translation.',
        '',
        'The fix is a transliteration, not a rewrite. Serbian Cyrillic and Latin',
        'are a strict one-to-one mapping — љ is lj, ђ is đ, ч is č, џ is dž — so',
        'only the letters change. Placeholders, HTML, the | separators and the',
        'names already in Latin (Beatrax, Gmail, OAuth, GDK, PIN) stay byte for',
        'byte, and nothing is reworded on the way through.',
        '',
        'The rule is one-directional. Latin letters are legal in every locale, so a',
        'brand name, a currency code, an IBAN or a class attribute inside a Cyrillic',
        'or Greek file is not an offence — only a NON-Latin letter in a locale that',
        'never declared that script. Widen it by declaring the script in',
        'LOCALE_SCRIPT_SHIPPED, never by excusing the file.',
    ]));
});

it('finds each non-Latin locale actually written in the script it declares', function (): void {
    $silent = [];
    $pinned = [];
    $checked = 0;

    foreach (LOCALE_SCRIPT_SHIPPED as $locale => $script) {
        if ($script === LOCALE_SCRIPT_LATIN) {
            continue;
        }

        foreach (localeScriptFiles($locale) as $path) {
            $checked++;
            if (localeScriptLettersIn($script, (string) file_get_contents($path)) !== []) {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $path);
            if (array_key_exists($relative, LOCALE_SCRIPT_PINS)) {
                $pinned[$relative] = true;

                continue;
            }

            $silent[] = $relative;
        }
    }

    expect($checked)->toBeGreaterThan(400);

    expect($silent)->toBe([], implode("\n", [
        'These files are in a locale that ships in a non-Latin script and carry',
        'none of it:',
        ...$silent,
        '',
        'The rule above forbids the wrong script; this is its other half, and it is',
        'the shape a Bulgarian or Greek file left in English takes. Parity is blind',
        'to it for the same reason: an untranslated line has the same key path, the',
        'same placeholders and the same segment count as a translated one.',
        '',
        'Translate the file. Where it genuinely holds no copy — an empty array, a',
        'lang file a module ships blank in every locale — pin it in',
        'LOCALE_SCRIPT_PINS with the reason and a pattern that proves the reason.',
    ]));

    $reached = array_keys($pinned);
    $granted = array_keys(LOCALE_SCRIPT_PINS);
    sort($reached);
    sort($granted);

    // A pin nobody reaches any more is a claim about the tree that stopped
    // being true, and it would otherwise sit here forever.
    expect($reached)->toBe($granted);
});

it('holds every pinned exemption to the reason it was granted for', function (): void {
    expect(LOCALE_SCRIPT_PINS)->not->toBe([], 'The pin map is empty, so this rule proves nothing about it.');

    $unreached = [];

    foreach (LOCALE_SCRIPT_PINS as $relative => $pin) {
        expect(is_file(base_path($relative)))->toBeTrue($relative.' is pinned here and does not exist.');

        $source = (string) file_get_contents(base_path($relative));
        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');

        $script = LOCALE_SCRIPT_SHIPPED[basename(dirname($relative))] ?? LOCALE_SCRIPT_LATIN;
        if ($script !== LOCALE_SCRIPT_LATIN && localeScriptLettersIn($script, $source) === []) {
            continue;
        }

        $unreached[] = $relative;
    }

    expect($unreached)->toBe([], implode("\n", [
        'These pins are no longer reached by the rule they were written for:',
        ...$unreached,
        '',
        'A pin is a claim under review, not a waiver. Each one says a specific file',
        'is not the defect and gives a pattern that proves it; a pin the rule has',
        'stopped reaching is an assertion about the tree that nothing re-checks, and',
        'it goes on excusing a file that may since have grown real copy in the wrong',
        'script. Delete the entry, or restore what earned it.',
    ]));
});
