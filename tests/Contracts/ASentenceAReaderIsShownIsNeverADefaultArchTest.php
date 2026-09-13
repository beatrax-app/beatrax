<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Symfony\Component\Finder\Finder;

// The phone asked the enclave to unlock with the sentence iOS prints on the
// Face ID sheet, and the call site passed nothing, so the parameter default
// answered: one English line in front of twenty-five readers who had set
// another language, at the moment the key to the ledger is released.

// A default is what makes it invisible. Every call site reads as complete, the
// translation exists and ships, and nothing resolves a key that is never named.
// A sentence a reader is shown therefore has no default: the signature is what
// makes each call site say which language it is asking in.

// A `??` fallback is the same shape wearing the other face. A report row read
// "Unknown account" in all twenty-six languages, between two siblings that both
// resolved a key, and a parity check cannot see it: parity compares locales to
// each other, and a key absent from all of them is absent from the comparison.

// Capitalised and more than one word. A locale code, a driver name, a date
// pattern and a column are none of those; a sentence is all of them.
const READER_SENTENCE_SHAPE = '/^\p{Lu}\p{Ll}+(?:\P{Lu}*\s\P{Z}+)+$/u';

/** @return list<string> the PHP the product ships, tests excluded */
function sentenceDefaultFiles(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(base_path('Modules'))->name('*.php')->notPath('tests') as $file) {
        $files[] = $file->getRealPath();
    }

    sort($files);

    return $files;
}

// A Throwable's $message is the one string-typed default the language itself
// asks for, and it reaches a log rather than a reader. The parent is resolved
// and asked, rather than the name being matched, so an exception that stops
// being one stops being exempt.
function sentenceDefaultIsThrowable(?string $parent): bool
{
    return $parent !== null && is_a($parent, Throwable::class, true);
}

// The walk holds templates as well as classes — `.blade.php` ends in `.php`, so
// every `.php` walk does — and token_get_all reads a template as one
// T_INLINE_HTML, reporting every island in it clean without reading it.
function sentenceDefaultSource(string $path): string
{
    return BladePhpSource::forPath($path, (string) file_get_contents($path));
}

/**
 * Every parameter in $path whose default is a quoted string.
 *
 * @return list<array{line: int, name: string, value: string, throwable: bool}>
 */
function sentenceDefaults(string $path): array
{
    $tokens = token_get_all(sentenceDefaultSource($path));
    $count = count($tokens);

    $namespace = '';
    $uses = [];
    $parent = null;
    $found = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_NAMESPACE) {
            $namespace = sentenceDefaultName($tokens, $i, $count);

            continue;
        }

        if (is_array($token) && $token[0] === T_USE) {
            $alias = sentenceDefaultName($tokens, $i, $count);

            if ($alias !== '') {
                $uses[substr((string) strrchr('\\'.$alias, '\\'), 1)] = $alias;
            }

            continue;
        }

        if (is_array($token) && $token[0] === T_EXTENDS) {
            $named = sentenceDefaultName($tokens, $i, $count);
            $parent = $uses[$named] ?? ($named === '' ? null : $namespace.'\\'.$named);

            continue;
        }

        if (is_array($token) && $token[0] === T_FUNCTION) {
            foreach (sentenceDefaultParameters($tokens, $i, $count) as $parameter) {
                $found[] = $parameter + ['throwable' => sentenceDefaultIsThrowable($parent)];
            }
        }
    }

    return $found;
}

/** The dotted name that follows a namespace, use or extends keyword. */
function sentenceDefaultName(array $tokens, int $from, int $count): string
{
    $name = '';

    for ($i = $from + 1; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name .= $token[1];

            continue;
        }

        break;
    }

    return ltrim($name, '\\');
}

/**
 * The string-literal defaults in the parameter list opening after $from.
 *
 * @return list<array{line: int, name: string, value: string}>
 */
function sentenceDefaultParameters(array $tokens, int $from, int $count): array
{
    $i = $from;
    while ($i < $count && $tokens[$i] !== '(') {
        $i++;
    }

    $depth = 0;
    $parameters = [];

    for (; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '(') {
            $depth++;

            continue;
        }

        if ($token === ')') {
            $depth--;

            if ($depth === 0) {
                break;
            }

            continue;
        }

        if ($depth !== 1 || ! is_array($token) || $token[0] !== T_VARIABLE) {
            continue;
        }

        $literal = sentenceDefaultLiteral($tokens, $i, $count);

        if ($literal !== null) {
            $parameters[] = ['line' => $literal[1], 'name' => $token[1], 'value' => $literal[0]];
        }
    }

    return $parameters;
}

/** @return array{0: string, 1: int}|null the quoted default assigned to the variable at $from */
function sentenceDefaultLiteral(array $tokens, int $from, int $count): ?array
{
    $i = $from + 1;

    while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
        $i++;
    }

    if ($i >= $count || $tokens[$i] !== '=') {
        return null;
    }

    $i++;

    while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
        $i++;
    }

    if ($i >= $count || ! is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }

    return [substr($tokens[$i][1], 1, -1), $tokens[$i][2]];
}

// The tree holds seventy-odd string-typed parameter defaults, and a walk that
// stops reading finds no sentence among them and calls the tree clean.
const SENTENCE_DEFAULT_FLOOR = 50;

it('never lets a parameter default answer for a sentence a reader is shown', function (): void {
    $offenders = [];
    $read = 0;

    foreach (sentenceDefaultFiles() as $path) {
        foreach (sentenceDefaults($path) as $default) {
            $read++;

            if ($default['throwable'] || preg_match(READER_SENTENCE_SHAPE, $default['value']) !== 1) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $path).':'.$default['line']
                .'  '.$default['name']." = '".$default['value']."'";
        }
    }

    expect($read)->toBeGreaterThan(
        SENTENCE_DEFAULT_FLOOR,
        'The reader found '.$read.' string-typed parameter defaults across '.count(sentenceDefaultFiles())
        .' files, which is what a walk that stopped reading looks like: no default found is no default to judge.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These parameters default to a sentence a reader is shown:',
        ...$offenders,
        '',
        'A default is what lets a call site omit the language it is asking in.',
        'Drop it, and pass Lang::get(...) from each call site instead.',
    ]));
});

/**
 * Every `??` whose right-hand side is a quoted string, in $path.
 *
 * @return list<array{line: int, value: string}>
 */
function sentenceFallbacks(string $path): array
{
    $tokens = token_get_all(sentenceDefaultSource($path));
    $count = count($tokens);
    $found = [];

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_COALESCE) {
            continue;
        }

        $j = $i + 1;

        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
            $found[] = ['line' => $tokens[$j][2], 'value' => substr($tokens[$j][1], 1, -1)];
        }
    }

    return $found;
}

// The tree falls back to a string literal 240 times, and a walk that stops
// reading finds no sentence among them and calls the tree clean.
const SENTENCE_FALLBACK_FLOOR = 150;

it('never lets a fallback answer for a sentence a reader is shown', function (): void {
    $offenders = [];
    $read = 0;

    foreach (sentenceDefaultFiles() as $path) {
        foreach (sentenceFallbacks($path) as $fallback) {
            $read++;

            if (preg_match(READER_SENTENCE_SHAPE, $fallback['value']) !== 1) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $path).':'.$fallback['line']
                ."  ?? '".$fallback['value']."'";
        }
    }

    expect($read)->toBeGreaterThan(
        SENTENCE_FALLBACK_FLOOR,
        'The reader found '.$read.' string fallbacks, which is what a walk that stopped reading looks like.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These fall back to a sentence a reader is shown:',
        ...$offenders,
        '',
        'A lookup that misses still faces a reader. Resolve a key for the miss,',
        'and add it to all 26 catalogues — a key in none of them is one a parity',
        'check has nothing to compare, so it reads as in parity.',
    ]));
});

// The verdict above is read off one pattern, and a pattern that stopped matching
// would report the tree clean. It is checked against the shapes a default really
// takes rather than against the tree, so a rewrite cannot quietly stop finding them.
it('reads a sentence as one, and a setting as not one', function (string $value, bool $sentence): void {
    expect(preg_match(READER_SENTENCE_SHAPE, $value) === 1)->toBe($sentence);
})->with([
    'the sentence the OS showed' => ['Unlock Beatrax', true],
    'a sentence with a stop' => ['Provider rate limit exceeded.', true],
    'a translated sentence' => ['Beatrax ontgrendelen', true],
    'a locale code' => ['en', false],
    'a driver name' => ['sqlite', false],
    'a single capitalised word' => ['Beatrax', false],
    'a date pattern' => ['Y-m-d', false],
    'a translation key' => ['auth::lock_screen.native_unlock_reason', false],
    'a column' => ['created_at', false],
    'a mime type' => ['application/json', false],
]);
