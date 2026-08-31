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

// preg_match_all answers false when the engine gives up -- a backtrack limit, a
// JIT stack limit on a long template -- and every rule in this file reads its
// answer as "nothing matched". A guard that stops reading has to say so: a
// silent false here is a clean tree reported over a scan that never ran.
function countedNounScanned(int|false $matched, string $what): int
{
    if ($matched === false) {
        throw new RuntimeException('the counted-noun '.$what.' scan stopped reading: '.preg_last_error_msg());
    }

    return $matched;
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
    countedNounScanned(preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)((?:\s+[A-Za-z][\w\'\/-]*){1,3})/', $line, $matches, PREG_SET_ORDER), 'lang line');

    foreach ($matches as $match) {
        if (! countedNounIsCountToken($match[1])) {
            continue;
        }

        // A range is never one of anything, so the noun after it is plural for
        // every value the pair can take. That is a shape, not a per-key
        // exception. Recognised by the PAIR rather than by the punctuation
        // between them: ":min..:max characters" and "between :min and :max
        // characters" are the same claim, and every locale words the join
        // differently -- Dutch "tussen", German "zwischen", French "de ... à".
        if (str_contains($line, ':min') && str_contains($line, ':max')) {
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
        'in this file — that list, and a line carrying both :min and :max, are the only exceptions.',
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
        countedNounScanned(preg_match_all("/(?:Lang::get|__|@lang|trans)\(\s*'([^']+)'/", $source, $matches), 'call site key');

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
// JavaScript reaches the same property with a `.`, so the walk splits on it too.
function countedNounIsCountVariable(string $name): bool
{
    $reached = preg_split('/->|\?->|\./', $name) ?: [];
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
    countedNounScanned(preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*'.$call.'/', $source, $direct), 'held line');
    $names = $direct[1];

    // An array of translated lines walked by foreach is the same variable one
    // hop later, and is how a breakdown line gets assembled a part at a time.
    countedNounScanned(preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*\[[^;]*?'.$call.'[^;]*?\];/s', $source, $arrays), 'held line array');
    foreach ($arrays[1] as $array) {
        countedNounScanned(preg_match_all('/foreach\s*\(\s*\$'.preg_quote($array, '/').'\s+as\s+(?:\$\w+\s*=>\s*)?\$(\w+)\s*\)/', $source, $loops), 'held line loop');
        $names = array_merge($names, $loops[1]);
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
        countedNounScanned(preg_match_all($pattern, $source, $matches, PREG_SET_ORDER), 'number beside line');
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

// A number the template FORMATS rather than echoes. The rules above read a
// count off a variable name, and none of them can see one: Fmt::number(...),
// number_format(...) and count(...) are calls, and the count they carry is
// inside the parentheses where no name test reaches it.
const COUNTED_NOUN_FORMATTED_NUMBER = '(?:Fmt::number|number_format|count)\s*\(';

it('never sets a formatted number beside a translated line', function (): void {
    $files = 0;
    $offenders = [];

    $pattern = '/\{\{\s*'.COUNTED_NOUN_FORMATTED_NUMBER.'[^{}]*\}\}'.COUNTED_NOUN_GAP
        .'\{\{[^{}]{0,160}(?:'.COUNTED_NOUN_FLAT_CALL.'|'.COUNTED_NOUN_CHOOSING_CALL.')/';

    foreach (countedNounCallSites() as $file) {
        $files++;
        $source = (string) $file->getContents();
        countedNounScanned(preg_match_all($pattern, $source, $matches, PREG_SET_ORDER), 'formatted number beside line');

        foreach ($matches as $match) {
            $offenders[] = $file->getRelativePathname().' — '.trim(preg_replace('/\s+/', ' ', $match[0]) ?? '');
        }
    }

    $offenders = array_values(array_unique($offenders));

    expect($files)->toBeGreaterThan(0, 'No call site was scanned, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These put a formatted number next to a translated line:',
        ...$offenders,
        '',
        'It is the numeral-then-word order the two rules above forbid, written so that',
        'neither of them can see it. Those read the count off a variable name, and there',
        'is no variable here to read: the count sits inside Fmt::number(), number_format()',
        'or count(), and what stands beside the line is a call. Every instance this rule',
        'was written for read correctly in English and wrongly at one — "1 Mappings",',
        '"1 new, 1 unchanged, 1 conflicts.", "Matches 1 transactions in your recent',
        'history." — and none of them could be fixed by a translator, because the noun',
        'they were handed had no arms and no numeral to agree with.',
        '',
        'A call is matched rather than a name because a formatted number is unambiguous:',
        'nothing formats a value that way except to show a reader a quantity. Move the',
        'numeral into the line and read it with Lang::choice($key, $n) — choice() fills',
        ':count through Fmt::number() itself, so the grouping marks the call site was',
        'reaching for come with it. Where the phrase carries a SECOND number, that one',
        'stays a replacement: Lang::choice($key, $total, [\'fetched\' => Fmt::number($n)]).',
        '',
        'Where two translated fragments bracket the number — a _prefix, a numeral, a',
        '_suffix — the same fix collapses all three into one line. A sentence assembled',
        'from parts pins the word order for twenty-six languages at once, and a translator',
        'handed "Matches" and "transactions in your recent history." cannot move either.',
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

// Every rule above reads a count PHP holds while it renders. An Alpine
// expression is evaluated after the response has left, where no locale rule
// table exists and Lang::choice cannot be handed a number that does not exist
// yet -- so a count reaching the reader from there is invisible to all of them.
const COUNTED_NOUN_ALPINE_ATTRIBUTE = '\bx-(?:text|html|data|init|effect|bind:[\w.\-]+)\s*=\s*"([^"]*)"';

// A translated line rendered into a JavaScript expression, in the spellings
// this tree writes it: @js(Lang::get(...)), {{ Js::from(Lang::get(...)) }} and
// a bare {{ }} echo. The trailing parenthesis and braces are optional because
// the same key is written all three ways within one template.
const COUNTED_NOUN_JS_LINE = '(?:@js\(\s*|\{\{\s*(?:Js::from\(\s*)?)Lang::(?:get|choice)\(\s*\'[^\']+\'\s*(?:,[^()]*)?\)\s*\)?\s*(?:\}\})?';

// A count reached in JavaScript: a dotted identifier chain and nothing else.
// A call is deliberately not matched -- humanBytes(x) and formatCount(x) are
// both on this tree and neither is a count governing the word beside it.
const COUNTED_NOUN_JS_NUMBER = '[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*';

/** @return list<string> every Alpine expression attribute value in $source */
function countedNounAlpineExpressions(string $source): array
{
    countedNounScanned(preg_match_all('/'.COUNTED_NOUN_ALPINE_ATTRIBUTE.'/', $source, $matches), 'Alpine attribute');

    return $matches[1];
}

it('never assembles a translated line from fragments in the browser', function (): void {
    $expressions = 0;
    $offenders = [];

    foreach (countedNounCallSites() as $file) {
        foreach (countedNounAlpineExpressions((string) $file->getContents()) as $expression) {
            $expressions++;

            $pattern = '/(?:\+\s*\'?\s*'.COUNTED_NOUN_JS_LINE.')|(?:'.COUNTED_NOUN_JS_LINE.'\s*\'?\s*\+)/';
            if (countedNounScanned(preg_match_all($pattern, $expression, $matches), 'browser line glue') === 0) {
                continue;
            }

            $offenders[] = $file->getRelativePathname().' — '.trim(preg_replace('/\s+/', ' ', $expression) ?? '');
        }
    }

    $offenders = array_values(array_unique($offenders));

    expect($expressions)->toBeGreaterThan(0, 'No Alpine expression was scanned, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These build a sentence in the browser by concatenating translated fragments:',
        ...$offenders,
        '',
        'A prefix, a value and a suffix glued with + is one sentence split into pieces,',
        'and the split is the damage. It pins the word order — numeral in the middle,',
        'noun after it — for every language at once, and hands each of twenty-six',
        'translators two halves with no way to say their language puts the value',
        'somewhere else. Slovak and Ukrainian already answered this palette by moving',
        'the count into brackets, which is a translator working around a call site.',
        '',
        'Where the value is a COUNT it is also wrong in English at one. "See all " + 1 +',
        '" results" reads "See all 1 results", and no rule in this file above could see',
        'it: there is no :placeholder in the lang line, because the number never passes',
        'through PHP at all.',
        '',
        'Write one line with the placeholder where the language wants it, and fill it in',
        'the browser: Lang::get(...) with :query in the middle, read through $line(); a',
        'counted line through Lang::arms(...) and $plural(), which carries the arms and',
        'the reader locale\'s own selection table so the browser picks the arm PHP would.',
        'A JavaScript n === 1 ? a : b is not the fix — it is English\'s two forms written',
        'where no locale rule can reach them, which the PHP rule above already forbids.',
    ]));
});

it('never sets a browser-rendered number beside a line that has no form to choose', function (): void {
    $files = 0;
    $offenders = [];

    $pattern = '/x-(?:text|html)\s*=\s*"\s*('.COUNTED_NOUN_JS_NUMBER.')\s*"[^<>]*>'
        .COUNTED_NOUN_GAP.'\{\{[^}]{0,140}(?:'.COUNTED_NOUN_FLAT_CALL.'|'.COUNTED_NOUN_CHOOSING_CALL.')/';

    foreach (countedNounCallSites() as $file) {
        $files++;
        $source = (string) $file->getContents();
        countedNounScanned(preg_match_all($pattern, $source, $matches, PREG_SET_ORDER), 'browser number beside line');

        foreach ($matches as $match) {
            if (countedNounIsCountVariable($match[1])) {
                $offenders[] = $file->getRelativePathname().' — '.trim(preg_replace('/\s+/', ' ', $match[0]) ?? '');
            }
        }
    }

    $offenders = array_values(array_unique($offenders));

    expect($files)->toBeGreaterThan(0, 'No call site was scanned, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These print a number from Alpine next to a translated line beside it:',
        ...$offenders,
        '',
        'This is the rule above it one layer out. The template has decided the numeral',
        'comes first and the word follows, and the word was written to fit — so it reads',
        'as finished in English and is wrong in every language that inflects the noun for',
        'the number, which the lang file gives it no way to do.',
        '',
        'It is worse than the PHP case in one respect: Lang::choice cannot rescue it,',
        'because the number is not known until the response has been delivered. Hand the',
        'arms to the browser instead — Lang::arms($key) carries every form plus the',
        'reader locale\'s selection table, taken from Laravel\'s own MessageSelector — and',
        'read it with the $plural() magic, whose :count lands inside the chosen arm.',
        '',
        'The number is read off the expression the same way the PHP rules read a variable',
        'name: off the last word, so stats.allFiles.count and visibleLines.length qualify',
        'and hit.amount does not. A call is not matched — humanBytes(bytes) is a size, not',
        'a count of the noun beside it — and the gap does not cross an opening tag.',
    ]));
});
