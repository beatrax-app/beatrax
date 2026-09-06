<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Measured on the desktop app at 1280 with the WCAG formula, colours resolved
// through a canvas because Tailwind v4 emits oklch(). Three pairings were below
// the 4.5:1 floor for normal text no matter which route they appeared on, so
// they are properties of the class list rather than of the page:
//
//   slate-400 on white ......... 2.63:1     slate-500 on slate-100 ... 4.35:1
//   slate-600 on slate-950 ..... 2.66:1  (a light-only colour, in dark mode)
//
// The last one is why a light-mode fix needs its dark half in the same breath:
// darkening the light value silently moved nine nodes below the floor at night.

/**
 * Every class attribute in every template a reader is shown. A fixture template
 * under tests/ is not one: a guard planting a class list to be read back would
 * otherwise report its own plant as a colour somebody has to fix.
 *
 * @return list<array{file: string, classes: string}>
 */
function templateClassAttributes(): array
{
    $found = [];

    foreach (['Modules', 'resources'] as $root) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($walk as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, '.blade.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $matches = PatternScan::all('/class="([^"]*)"/', (string) file_get_contents($path));

            foreach ($matches[1] as $classes) {
                $found[] = ['file' => str_replace(base_path().'/', '', $path), 'classes' => $classes];
            }
        }
    }

    return $found;
}

/**
 * The floors a single class list falls below, named the way the four rules
 * below name them, so a control can drive the same reading the walk drives.
 *
 * @param  list<string>  $tokens
 * @return list<string>
 */
function mutedTextFaults(array $tokens): array
{
    $carries = static fn (string $prefix): bool => array_filter(
        $tokens,
        static fn (string $token): bool => str_contains($token, $prefix),
    ) !== [];

    $faults = [];

    if (in_array('text-slate-400', $tokens, true)) {
        $faults[] = 'slate-400 on a light surface';
    }

    if (in_array('bg-slate-100', $tokens, true) && in_array('text-slate-500', $tokens, true)) {
        $faults[] = 'slate-500 on slate-100';
    }

    if (in_array('placeholder:text-slate-500', $tokens, true) && ! $carries('dark:placeholder:')) {
        $faults[] = 'a placeholder with no dark half';
    }

    if (in_array('text-slate-600', $tokens, true) && ! $carries('dark:text-')) {
        $faults[] = 'a light-only text colour';
    }

    return $faults;
}

/**
 * @param  callable(list<string>): bool  $fails
 * @return list<string>
 */
function classListsWhere(callable $fails): array
{
    $offenders = [];
    $attributes = templateClassAttributes();

    // Four thousand class attributes are written across the templates. A walk
    // that read none of them would report every colour as above the floor, and
    // all four rules below would agree.
    expect(count($attributes))->toBeGreaterThan(
        1000,
        'Read '.count($attributes).' class attributes, too few for an empty offender list to mean anything.',
    );

    foreach ($attributes as $attribute) {
        $tokens = PatternScan::split('/\s+/', trim($attribute['classes']));

        if ($fails($tokens)) {
            $offenders[] = $attribute['file'];
        }
    }

    return array_values(array_unique($offenders));
}

it('does not write slate-400 on a light surface', function (): void {
    $offenders = classListsWhere(
        static fn (array $tokens): bool => in_array('slate-400 on a light surface', mutedTextFaults($tokens), true)
    );
    sort($offenders);

    expect($offenders)->toBe(
        [],
        'slate-400 reads 2.63:1 on white and 2.45:1 on slate-100, both under the 4.5:1 floor. '
        ."Light text goes to slate-600; slate-400 belongs behind a dark: prefix:\n  "
        .implode("\n  ", $offenders)
    );
});

it('does not paint slate-500 onto a surface it also paints slate-100', function (): void {
    $offenders = classListsWhere(
        static fn (array $tokens): bool => in_array('slate-500 on slate-100', mutedTextFaults($tokens), true)
    );
    sort($offenders);

    expect($offenders)->toBe(
        [],
        "slate-500 on slate-100 is 4.35:1 wherever it appears, because the element carries both:\n  "
        .implode("\n  ", $offenders)
    );
});

// The same trap, one layer down: a placeholder has no textContent, so the first
// sweep could not see it at all. slate-500 is 4.76:1 on white and still visibly
// lighter than a slate-900 value, which is the distinction a placeholder keeps;
// against slate-900 it is 3.74:1 and needs the dark half.
it('gives a placeholder its dark half too', function (): void {
    $offenders = classListsWhere(
        static fn (array $tokens): bool => in_array('a placeholder with no dark half', mutedTextFaults($tokens), true)
    );
    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A placeholder set only for light mode reads 3.74:1 at night:\n  "
        .implode("\n  ", $offenders)
    );
});

it('gives a light-only text colour its dark half', function (): void {
    $offenders = classListsWhere(
        static fn (array $tokens): bool => in_array('a light-only text colour', mutedTextFaults($tokens), true)
    );
    sort($offenders);

    expect($offenders)->toBe(
        [],
        'slate-600 is 2.66:1 against the dark background, so a class list that names it without '
        ."naming a dark: colour is legible in one theme only:\n  ".implode("\n  ", $offenders)
    );
});

it('reads each of the four pairings off a class list, and leaves the corrected ones alone', function (): void {
    expect(mutedTextFaults(['text-slate-400', 'text-sm']))
        ->toBe(['slate-400 on a light surface'], '2.63:1 on white is the pairing that started this');

    expect(mutedTextFaults(['bg-slate-100', 'text-slate-500']))
        ->toBe(['slate-500 on slate-100'], 'the element carries both, so the pairing is a property of the class list');

    expect(mutedTextFaults(['placeholder:text-slate-500']))
        ->toBe(['a placeholder with no dark half'], 'a placeholder has no textContent, which is why the first sweep could not see it');

    expect(mutedTextFaults(['text-slate-600']))
        ->toBe(['a light-only text colour'], 'slate-600 is 2.66:1 at night, so the dark half is not optional');

    expect(mutedTextFaults(['text-slate-600', 'dark:text-slate-300', 'placeholder:text-slate-500', 'dark:placeholder:text-slate-400']))
        ->toBe([], 'a colour with its dark half named is legible in both themes, which is what the fix looks like');

    expect(mutedTextFaults(['text-slate-500', 'bg-white']))
        ->toBe([], 'slate-500 is 4.76:1 on white, and this rule is not about it there');
});
