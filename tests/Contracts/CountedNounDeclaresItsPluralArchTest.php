<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-count-beside-a-noun-that-never-declared-itself-plural
 */
function countedNounSourceFiles(): array
{
    $files = glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [];
    sort($files);

    return array_values($files);
}

// The rule's vocabulary, not an exemption list: a placeholder named one of
// these fills in a number, so whatever noun follows it has to agree with it.
// A new count placeholder goes here — widening the noun test instead is how
// ":name dips to" starts reading as a counted noun.
const COUNTED_NOUN_COUNT_TOKENS = [
    'applied', 'count', 'duplicates', 'failed', 'hits', 'inserted', 'length', 'max', 'min',
    'n', 'num', 'number', 'remaining', 'seen', 'shown', 'size', 'total',
];

// Words ending in s that are not plural nouns: third-person verbs, Latin
// singulars, and the few English words that merely look plural. Every one of
// these was a real false positive on the tree, so the list is load-bearing —
// it is where a genuine exception belongs.
const COUNTED_NOUN_NOT_PLURAL = [
    'access', 'address', 'alias', 'always', 'as', 'bonus', 'bus', 'class', 'does', 'else',
    'focus', 'gas', 'goes', 'has', 'hers', 'his', 'is', 'its', 'less', 'means', 'minus',
    'news', 'obvious', 'ours', 'pass', 'perhaps', 'plus', 'press', 'previous', 'process',
    'serious', 'sometimes', 'status', 'success', 'this', 'thus', 'unless', 'us', 'various',
    'was', 'yes', 'yours',
];

function countedNounReadsAsPlural(string $word): bool
{
    $bare = mb_strtolower(trim($word, '.,;:!?()[]—–-'));

    if (mb_strlen($bare) < 4 || ! str_ends_with($bare, 's') || str_ends_with($bare, 'ss')) {
        return false;
    }

    return ! in_array($bare, COUNTED_NOUN_NOT_PLURAL, true);
}

function countedNounIsCountToken(string $name): bool
{
    return in_array(mb_strtolower($name), COUNTED_NOUN_COUNT_TOKENS, true)
        || countedNounReadsAsPlural($name);
}

/**
 * @param  array<array-key, mixed>  $translations
 * @return array<string, string>
 */
function countedNounStrings(array $translations, string $prefix = ''): array
{
    $flat = [];
    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += countedNounStrings($value, $path);
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

/** @return string|null the offending fragment, or null when the line is clean */
function countedNounOffence(string $line): ?string
{
    if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)((?:\s+[A-Za-z][\w\'\/-]*){1,3})/', $line, $matches, PREG_SET_ORDER) === false) {
        return null;
    }

    foreach ($matches as $match) {
        if (! countedNounIsCountToken($match[1])) {
            continue;
        }

        // ":min..:max characters" spans a range, which is never one of
        // anything, so the noun after it is plural for every value the pair
        // can take. That is a shape, not a per-key exception.
        $token = preg_quote($match[1], '/');
        if (preg_match('/[.\x{2013}\x{2014}-]{2}\s*:'.$token.'|:'.$token.'\s*[.\x{2013}\x{2014}-]{2}/u', $line) === 1) {
            continue;
        }

        foreach (preg_split('/\s+/', trim($match[2])) ?: [] as $word) {
            if (countedNounReadsAsPlural($word)) {
                return trim($match[0]);
            }
        }
    }

    return null;
}

function countedNounCallSites(): Finder
{
    return Finder::create()
        ->files()
        ->in([base_path('Modules'), base_path('resources/views')])
        ->name(['*.php', '*.blade.php'])
        ->notPath('tests')
        ->notPath('Resources/lang');
}

/** @return array<string, string> translation namespace => module directory name */
function countedNounNamespaces(): array
{
    $namespaces = [];
    foreach (glob(base_path('Modules/*/Providers/*.php')) ?: [] as $provider) {
        $source = (string) file_get_contents($provider);
        if (preg_match("/loadModuleResources\(\s*'([^']+)'/", $source, $match) === 1) {
            $namespaces[$match[1]] = basename(dirname($provider, 2));
        }
    }

    return $namespaces;
}

it('declares a plural before it puts a count next to a noun', function (): void {
    $files = countedNounSourceFiles();
    expect($files)->not->toBeEmpty('No English lang file was found, so this rule checked nothing.');

    $offenders = [];

    foreach ($files as $file) {
        $translations = require $file;
        if (! is_array($translations)) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file);

        foreach (countedNounStrings($translations) as $path => $line) {
            if (str_contains($line, '|')) {
                continue;
            }

            $offence = countedNounOffence($line);
            if ($offence !== null) {
                $offenders[] = $relative.' ['.$path.'] — "'.$offence.'"';
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These English strings interpolate a count beside a bare plural noun and carry no | selector:',
        ...$offenders,
        '',
        'At a count of one the English reads wrong — "1 errors", "1 attempts remaining" —',
        'and that is the smaller half of it. The string ships in 26 locales. English has',
        'two plural forms; Polish, Czech, Croatian, Slovenian and Lithuanian have three or',
        'four, and several of them pick the form from the final digit rather than the',
        'magnitude. A line with no | gives the translator nowhere to put their own grammar,',
        'so those translations are wrong by construction rather than by mistake.',
        '',
        'Two ways out, both fine:',
        '  - Pluralise it. Give the en line every form English needs, give each locale as',
        '    many segments as it selects between, and read it with Lang::choice($key, $n).',
        '    TranslationParityArchTest then enforces the segment count per locale.',
        '  - Reword so the count stops governing the noun. ":count selected" and',
        '    "Files selected: :count" agree with every number in every language.',
        '',
        'A count that cannot be one is still flagged, and is still worth pluralising: the',
        'constant behind it changes, and 22 needs a different Polish form from 8.',
        'If the flagged word is not a plural noun at all, add it to COUNTED_NOUN_NOT_PLURAL',
        'in this file — that list, and the ":min..:max" range shape, are the only exceptions.',
    ]));
});

it('reads every pluralised line through Lang::choice', function (): void {
    $pluralised = [];
    $namespaces = array_flip(countedNounNamespaces());
    expect($namespaces)->not->toBeEmpty('No module translation namespace was found, so this rule checked nothing.');

    foreach (countedNounSourceFiles() as $file) {
        $translations = require $file;
        if (! is_array($translations)) {
            continue;
        }

        $module = basename(dirname($file, 4));
        if (! array_key_exists($module, $namespaces)) {
            continue;
        }

        $group = basename($file, '.php');
        foreach (countedNounStrings($translations) as $path => $line) {
            if (str_contains($line, '|')) {
                $pluralised[$namespaces[$module].'::'.$group.'.'.$path] = true;
            }
        }
    }

    expect($pluralised)->not->toBeEmpty('No pluralised line was found, so this rule checked nothing.');

    $offenders = [];

    foreach (countedNounCallSites() as $file) {
        $source = (string) $file->getContents();
        if (preg_match_all("/(?:Lang::get|__|@lang|trans)\(\s*'([^']+)'/", $source, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $key) {
            if (array_key_exists($key, $pluralised)) {
                $offenders[] = $file->getRelativePathname().' — '.$key;
            }
        }
    }

    $offenders = array_values(array_unique($offenders));

    expect($offenders)->toBe([], implode("\n", [
        'These call sites read a pluralised line with a non-choosing translator call:',
        ...$offenders,
        '',
        'Lang::get() returns the whole line, separators and all, so the reader is shown',
        '"1 transaction|1 transactions" verbatim. Nothing throws and no test below this',
        'one notices, because the line is a perfectly valid string.',
        '',
        'Pass the number: Lang::choice($key, $n) fills :count for you and picks the form',
        'the reader\'s locale selects for $n. Where the phrase is placed inside a longer',
        'sentence, choose it first and hand the result in as a replacement — that is how a',
        'sentence carrying two counts gives each of them its own form.',
    ]));
});

it('never picks a plural form with a comparison in PHP', function (): void {
    $files = 0;
    $offenders = [];

    foreach (countedNounCallSites() as $file) {
        $files++;
        $source = (string) $file->getContents();

        // The shape is a count compared against one, choosing between two
        // translation keys. It hard-codes English's two forms into PHP, where
        // no locale's rules can reach it, and reads as deliberate care.
        if (preg_match('/(?:===|!==|==|>|<)\s*1\s*\?[^;]{0,240}?(?:Lang::get|__|trans)\([^;]{0,240}?:\s*(?:Lang::get|__|trans)\(/s', $source, $match) === 1) {
            $offenders[] = $file->getRelativePathname().' — '.trim(preg_replace('/\s+/', ' ', $match[0]) ?? '');
        }
    }

    expect($files)->toBeGreaterThan(0, 'No call site was scanned, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These branch on a count to pick between two translation keys:',
        ...$offenders,
        '',
        'That is the same defect with the plural moved out of the lang file and into PHP.',
        'The branch says English: one form for 1 and one for everything else. Polish needs',
        'three and Slovenian four, and neither can be reached from a PHP ternary — the',
        'singular key simply never renders for them beyond the number 1.',
        '',
        'Collapse the two keys into one pluralised line and call Lang::choice($key, $n).',
        'The locale\'s own rule table then picks the segment, and the parity test can see',
        'that the line has as many segments as each locale needs.',
    ]));
});
