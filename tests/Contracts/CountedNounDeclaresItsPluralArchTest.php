<?php

declare(strict_types=1);

use Illuminate\Translation\MessageSelector;
use Modules\Core\Public\Enums\Locale;
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

// Asked of Laravel's rule table rather than restated, for the same reason the
// parity test asks it: the two numbers have to come from one authority or a
// locale ends up with a floor and a ceiling that disagree.
/** @return int how many `|` segments trans_choice can select in this locale */
function countedNounSelectableForms(string $locale): int
{
    $selector = new MessageSelector;
    $indexes = [];
    foreach (range(0, 200) as $number) {
        $indexes[$selector->getPluralIndex($locale, $number)] = true;
    }

    return max(array_keys($indexes)) + 1;
}

/** @return bool whether any segment pins itself to numbers with a {n} or [a,b] condition */
function countedNounHasExplicitRange(string $line): bool
{
    foreach (explode('|', $line) as $segment) {
        if (preg_match('/^\s*[\{\[]/', $segment) === 1) {
            return true;
        }
    }

    return false;
}

it('never ships a plural segment the locale can never select', function (): void {
    $problems = [];

    foreach (Locale::cases() as $case) {
        $locale = $case->value;
        $forms = countedNounSelectableForms($locale);

        foreach (countedNounSourceFiles() as $enFile) {
            $targetFile = str_replace('/lang/en/', '/lang/'.$locale.'/', $enFile);
            if (! is_file($targetFile)) {
                continue;
            }

            $translated = require $targetFile;
            if (! is_array($translated)) {
                continue;
            }

            $rel = str_replace(base_path().'/', '', $targetFile);
            foreach (countedNounStrings($translated) as $path => $line) {
                if (! str_contains($line, '|') || countedNounHasExplicitRange($line)) {
                    continue;
                }

                $actual = substr_count($line, '|') + 1;
                if ($actual > $forms) {
                    $problems[] = $rel.' ['.$path.']: '.$actual.' segments, '.$locale.' selects between '.$forms;
                }
            }
        }
    }

    expect($problems)->toBe([], implode("\n", [
        'These lines carry a segment their locale has no number to reach it with:',
        ...$problems,
        '',
        'trans_choice asks the rule table for an index and returns that segment.',
        'Turkish always answers 0 and Hungarian only ever 0 or 1, so a third form',
        'written for them is dead text: it renders for no count, no test below this',
        'one reads it, and the reviewer who wrote it believes it shipped.',
        '',
        'The parity test guards the floor — too few segments and a locale silently',
        'renders its singular for every "many". This is the ceiling, and the two',
        'together say the segment count is the locale\'s to decide, not the',
        'translator\'s. Delete the surplus segments.',
        '',
        'A form the rule table cannot select but the copy genuinely needs — a',
        'distinct wording at zero, which no locale here selects on — is written as',
        'an explicit {0} range instead. Those are matched by number before the rule',
        'table is consulted at all, and this rule leaves such lines alone.',
    ]));
});

// The lang-file rules above read a count token out of a translated line. This
// one reads it out of a PHP variable name, off the last word so $openCount and
// $rowCount qualify by the same vocabulary and $rows does not. A property is
// read the same way: $preview->dedupedTotalCount names its count where it sits.
function countedNounIsCountVariable(string $name): bool
{
    $reached = preg_split('/->|\?->/', $name) ?: [];
    $words = preg_split('/(?=[A-Z])|_/', (string) end($reached)) ?: [];

    return in_array(mb_strtolower((string) end($words)), COUNTED_NOUN_COUNT_TOKENS, true);
}

const COUNTED_NOUN_FLAT_CALL = '(?:Lang::get|(?<![\w:>])__|(?<![\w:>])trans)\(';

const COUNTED_NOUN_CHOOSING_CALL = '(?:Lang::choice|(?<![\w:>])trans_choice)\(';

// A method call and an array index reach a count too, and adding either finds
// nothing on this tree — while an index would reach $navCounts['chains'], which
// is the sanctioned badge. Unmeasured width is how a narrowing stops meaning
// anything, so this stops at the two shapes a count is actually written in.
const COUNTED_NOUN_NUMBER = '[A-Za-z_]\w*(?:(?:->|\?->)[A-Za-z_]\w*)*';

// Between the number and the line: visible text, or the closing tag a numeral
// in its own <span> ends with and nothing else. An opening tag would pair one
// badge's count with the next badge's label, and a "·" would pair two colon
// labels — both are shapes on this tree, and both are the reword, not the fault.
const COUNTED_NOUN_GAP = '(?:[^<>"{}]{0,16}|[ \t\r\n]*<\/[a-zA-Z][a-zA-Z0-9]*>[ \t\r\n]*)';

/** @return string a regex alternation of variables in this file that hold a line read with $call */
function countedNounTranslatedVariables(string $source, string $call): string
{
    $names = [];
    if (preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*'.$call.'/', $source, $direct) !== false) {
        $names = $direct[1];
    }

    // An array of translated lines walked by foreach is the same variable one
    // hop later, and is how a breakdown line gets assembled a part at a time.
    if (preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*\[[^;]*?'.$call.'[^;]*?\];/s', $source, $arrays) !== false) {
        foreach ($arrays[1] as $array) {
            if (preg_match_all('/foreach\s*\(\s*\$'.preg_quote($array, '/').'\s+as\s+(?:\$\w+\s*=>\s*)?\$(\w+)\s*\)/', $source, $loops) !== false) {
                $names = array_merge($names, $loops[1]);
            }
        }
    }

    $names = array_values(array_unique(array_filter($names)));

    return $names === [] ? '(?!)' : implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));
}

/** @return list<string> the fragments in this call site that set a number beside a $call line */
function countedNounNumberBesideLine(string $source, string $call): array
{
    $held = countedNounTranslatedVariables($source, $call);

    $patterns = [
        '/\$('.COUNTED_NOUN_NUMBER.')\s*\.\s*(?:\'[^\']*\'\s*\.\s*)?(?:'.$call.'|\$(?:'.$held.')\b)/',
        '/\{\{\s*\$('.COUNTED_NOUN_NUMBER.')\s*\}\}'.COUNTED_NOUN_GAP.'\{\{[^}]{0,140}'.$call.'/',
        '/\{\{\s*\$('.COUNTED_NOUN_NUMBER.')\s*\}\}'.COUNTED_NOUN_GAP.'\{\{\s*\$(?:'.$held.')\s*\}\}/',
    ];

    $hits = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
            continue;
        }
        foreach ($matches as $match) {
            if (countedNounIsCountVariable($match[1])) {
                $hits[] = trim(preg_replace('/\s+/', ' ', $match[0]) ?? '');
            }
        }
    }

    return array_values(array_unique($hits));
}

/** @return list<string> */
function countedNounOffendingCallSites(string $call): array
{
    $offenders = [];

    foreach (countedNounCallSites() as $file) {
        foreach (countedNounNumberBesideLine((string) $file->getContents(), $call) as $hit) {
            $offenders[] = $file->getRelativePathname().' — '.$hit;
        }
    }

    return $offenders;
}

it('never sets a number beside a line that has no form to choose', function (): void {
    $files = iterator_count(countedNounCallSites());
    $offenders = countedNounOffendingCallSites(COUNTED_NOUN_FLAT_CALL);

    expect($files)->toBeGreaterThan(0, 'No call site was scanned, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These put a number next to a line read with Lang::get(), which returns one form:',
        ...$offenders,
        '',
        'The rules above catch a count beside a plural noun. This is the same defect one',
        'word to the left: a count beside an adjective. English adjectives do not inflect,',
        'so "2 large" and "3 open" read correctly and nothing looks wrong — but an',
        'adjective agrees with its number in every Slavic locale the app ships, and Polish',
        'needs "otwarte" at two and "otwartych" at five off one key that can only hold one',
        'of them. A ratio is worse again: "2/3 pinned" agrees with neither number, and',
        'several languages cannot assemble it from a bare adjective at all.',
        '',
        'The fix is the fix for a noun. Move the numeral inside the line, give it as many',
        'segments as each locale selects between, and read it with Lang::choice($key, $n).',
        'Where two numbers meet in one phrase, one key carries both — ":count of :max',
        'pinned" — and the cap arrives as a replacement rather than a literal.',
        '',
        'This rule reads the number off the variable name, so it sees $openCount and $n',
        'and leaves $rows alone. It looks only at the number-then-line order and only at',
        'the non-choosing calls; a label whose number follows a colon is a reword, not an',
        'offence, and that is why the reverse order is deliberately not matched.',
    ]));
});

it('never sets a number beside the line that was given the number', function (): void {
    $files = iterator_count(countedNounCallSites());
    $offenders = countedNounOffendingCallSites(COUNTED_NOUN_CHOOSING_CALL);

    expect($files)->toBeGreaterThan(0, 'No call site was scanned, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These print a number in the template beside a line read with Lang::choice():',
        ...$offenders,
        '',
        'Every arm is there and the right one is selected, so the count is not the bug.',
        'What the template has pinned is the order — numeral, space, word — and the arms',
        'were written to fit it, which is why they read as finished. A translator handed',
        '"row|rows" cannot put the numeral anywhere else, cannot decline it, and cannot',
        'attach the counter word several of these locales want between the two.',
        '',
        'choice() fills :count from the number it was already passed, so the arms take it',
        'for free: ":count row|:count rows" renders exactly what renders today and the',
        'call site loses an interpolation rather than gaining one.',
        '',
        'Styling aimed at the numeral alone moves out to the element wrapping the whole',
        'phrase — tabular-nums still only reaches digits there, and a weight or colour',
        'that covered one number now covers the phrase it belongs to. That is a layout',
        'change to make deliberately; leaving the numeral outside is not the way to dodge it.',
    ]));
});
