<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Support\CurrencyNames;
use Modules\Ledger\Public\ValueObjects\Money;

// A currency code is data, not copy. "Settled EUR" over a column that prints
// each row's own currency, and "Amount (EUR)" over a PDF that prints the
// account's, were true only for the ledger they were written against — and
// BaseCurrencyIsTheOnlyEuroArchTest skips lang/ on the grounds that
// translations are data, so nothing was watching them.

/**
 * Every locale, not English alone. A code reaches a reader through whichever
 * file their locale resolves to, and the twenty-five translated copies of a
 * line are exactly where the English one is least likely to be re-read.
 *
 * @return list<string>
 */
function copyCurrencyCodeFiles(): array
{
    $files = array_merge(
        (array) glob(base_path('Modules/*/Resources/lang/*/*.php')),
        (array) glob(base_path('lang/*/*.php')),
    );

    $paths = array_map(strval(...), $files);
    sort($paths);

    return array_values($paths);
}

/**
 * @param  array<array-key, mixed>  $strings
 * @return array<string, string>
 */
function copyCurrencyCodeFlatten(array $strings, string $prefix = ''): array
{
    $flat = [];
    foreach ($strings as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += copyCurrencyCodeFlatten($value, $path);
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

/**
 * Read once and held: both rules below walk the same four thousand files, and
 * requiring each of them twice is the only reason to do it twice.
 *
 * @return array<string, array<string, string>> repo-relative path => key => line
 */
function copyCurrencyCodeLines(): array
{
    /** @var array<string, array<string, string>>|null $lines */
    static $lines = null;

    if ($lines !== null) {
        return $lines;
    }

    $lines = [];

    foreach (copyCurrencyCodeFiles() as $path) {
        /** @var array<array-key, mixed> $strings */
        $strings = require $path;

        $lines[str_replace(base_path().'/', '', $path)] = copyCurrencyCodeFlatten($strings);
    }

    return $lines;
}

/**
 * @param  array<string, string>  $flat
 * @param  list<string>  $needles
 * @return list<string> `[key] line` for each line naming one
 */
function copyCurrencyOffendersIn(array $flat, array $needles, bool $wholeWord): array
{
    $offenders = [];

    foreach ($flat as $key => $value) {
        foreach ($needles as $needle) {
            // A letter either side, not `\b`: `\w` is ASCII, so the boundary
            // `\bSEK\b` finds falls between the É and the S of the Hungarian
            // ELEMZÉSEK and reports a heading as a Swedish krona. Every locale
            // but English writes letters `\w` does not know.
            $names = $wholeWord
                ? preg_match('/(?<!\p{L})'.preg_quote($needle, '/').'(?!\p{L})/u', $value) === 1
                : str_contains($value, $needle);

            if ($names) {
                $offenders[] = '['.$key.'] '.$value;
            }
        }
    }

    return $offenders;
}

/**
 * Both rules read the same denominators, and both are empty over a walk that
 * globbed nothing. The floors sit far under today's 4,104 files and 111,890
 * lines.
 */
function copyCurrencyAssertTheWalkRead(): void
{
    $lines = copyCurrencyCodeLines();
    $strings = array_sum(array_map(count(...), $lines));

    expect(count($lines))->toBeGreaterThan(
        1000,
        'the walk read '.count($lines).' lang files, which is too few to be twenty-six locales.'
    );

    expect($strings)->toBeGreaterThan(
        20000,
        'the walk flattened '.$strings.' translated lines, which is too few to be this tree.'
    );
}

// Every code the picker offers, not the four the enum declares: the install
// ships thirty-one currencies and this rule knew four of them, so "Statement
// balance (CHF)" was copy it could not read.
//
// One code is held out, with its reason. A code that is also an ordinary word
// reports the word, and the offender list is then noise a reader learns to
// scroll past — which is how a rule stops being read at all.
/** @var array<string, string> code => why it cannot be searched for as a word */
const COPY_CURRENCY_CODES_THAT_ARE_ALSO_WORDS = [
    'PHP' => 'The Philippine peso shares its code with the language this application is written in, and fifty-two translated lines tell a contributor where the PHP files live. The peso has no symbol needle either, so it is the one currency this rule does not cover — name it with a placeholder like every other.',
];

it('never writes a currency code into a translated string', function (): void {
    copyCurrencyAssertTheWalkRead();

    $codes = array_values(array_diff(CurrencyNames::codes(), array_keys(COPY_CURRENCY_CODES_THAT_ARE_ALSO_WORDS)));

    expect(count($codes))->toBeGreaterThan(
        count(Currency::cases()),
        'The needle set is no wider than the enum, so most of the currencies a reader can pick are unguarded.',
    );

    $offenders = [];
    foreach (copyCurrencyCodeLines() as $path => $flat) {
        foreach (copyCurrencyOffendersIn($flat, $codes, wholeWord: true) as $offender) {
            $offenders[] = $path.' '.$offender;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These strings name a currency the figure beside them is not guaranteed to be in:',
        ...$offenders,
        '',
        'Name the currency with a placeholder the caller fills from the data, or',
        'leave it out — the amount already carries its own symbol.',
    ]));
});

// A symbol is the same claim as a code, made shorter: "Amount (€)" over a field
// typed in the account's own denomination, and "dips below €0" over a calendar
// drawn in the reader's. Both were true only of the ledger they were written
// against.
it('never writes a currency symbol into a translated string', function (): void {
    copyCurrencyAssertTheWalkRead();

    $symbols = array_values(Money::SYMBOLS);
    expect($symbols)->not->toBeEmpty('Money declares no symbol, so this rule looked for nothing.');

    $offenders = [];
    foreach (copyCurrencyCodeLines() as $path => $flat) {
        foreach (copyCurrencyOffendersIn($flat, $symbols, wholeWord: false) as $offender) {
            $offenders[] = $path.' '.$offender;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These strings carry a currency symbol the figure beside them may not be in:',
        ...$offenders,
        '',
        'Interpolate the amount, or the symbol, from the currency the caller holds.',
    ]));
});

// Both rules report on a tree that holds none of what they look for, so the
// readers are driven against planted lines. The near-misses are the two shapes
// that legitimately survive: a placeholder the caller fills, and a longer word
// the code happens to sit inside.
it('tells a currency written into copy from a placeholder and from a word containing it', function (): void {
    $flat = [
        'settled.header' => 'Settled EUR',
        'amount.label' => 'Amount (:currency)',
        'europe.note' => 'EUROPE, and neuro too',
        'below.zero' => 'dips below €0',
        'plain' => 'dips below zero',
    ];

    expect(copyCurrencyOffendersIn($flat, ['EUR', 'USD'], wholeWord: true))
        ->toBe(['[settled.header] Settled EUR'])
        ->and(copyCurrencyOffendersIn($flat, ['€', '$'], wholeWord: false))
        ->toBe(['[below.zero] dips below €0'])
        ->and(copyCurrencyOffendersIn([], ['EUR'], wholeWord: true))->toBe([]);
});

it('flattens a nested lang array to the keys a caller writes', function (): void {
    expect(copyCurrencyCodeFlatten(['a' => ['b' => 'one', 'c' => ['d' => 'two']], 'e' => 'three', 'f' => 4]))
        ->toBe(['a.b' => 'one', 'a.c.d' => 'two', 'e' => 'three']);
});
