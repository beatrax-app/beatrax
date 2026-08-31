<?php

declare(strict_types=1);

use Tests\Contracts\Support\ColourPairs;
use Tests\Contracts\Support\ThemeColour;

// `background: var(--color-text)` is the inverted treatment and is correct.
// The defect is the partner: `color: #fff` beside it cannot follow the theme,
// so the Save button in the Tax popover was white on #f1f5f9 at night, 1.10:1.
// The muted-colour rule reads class attributes, and this pairing has none.

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-pair-of-colours-declared-together-is-measurable-without-a-browser
 */

/**
 * @return list<array{file: string, line: int, themes: list<string>, background: string, color: string}>
 */
function colourPairsInEveryTemplate(): array
{
    $pairs = [];

    foreach (ColourPairs::templates() as $template) {
        $pairs = array_merge(
            $pairs,
            ColourPairs::inInlineStyles($template['path'], $template['source']),
            ColourPairs::inPhpStrings($template['path'], $template['source']),
        );
    }

    return $pairs;
}

/**
 * @return array{failing: list<string>, unreadable: list<string>, transparent: list<string>, measured: int}
 */
function colourPairsInTheSaveButtonAsItShipped(): array
{
    $path = 'tests/Contracts/Fixtures/Contrast/a-save-button-drawn-in-hardcoded-white.blade';
    $source = (string) file_get_contents(base_path($path));

    return ColourPairs::measure(ColourPairs::inInlineStyles($path, $source));
}

// The reason the guard converts rather than pattern-matches, in the one value
// that made it necessary. `.srch-sheet-apply` wrote `color: oklch(99% 0 0)` on
// `--color-blue`; a regex reading 99, 0 and 0 as channels calls that pair 5.40:1
// and passes it. Converted, it is 2.47:1 and fails.
it('converts an oklch value rather than reading its numbers as channels', function (): void {
    $blue = ThemeColour::resolve('var(--color-blue)', ThemeColour::DARK);
    $nearWhite = ThemeColour::resolve('oklch(99% 0 0)', ThemeColour::DARK);

    expect($nearWhite)->not->toBeNull();
    expect(array_map('round', array_slice((array) $nearWhite, 0, 3)))->toBe([252.0, 252.0, 252.0]);

    expect(round(ThemeColour::ratio($nearWhite, $blue), 2))->toBe(2.47);
    expect(round(ThemeColour::ratio([99.0, 0.0, 0.0, 1.0], $blue), 2))->toBe(5.40);
});

it('resolves a token to the value each theme gives it', function (): void {
    expect(ThemeColour::resolve('var(--color-text)', ThemeColour::LIGHT))->toBe([15.0, 23.0, 42.0, 1.0]);
    expect(ThemeColour::resolve('var(--color-text)', ThemeColour::DARK))->toBe([241.0, 245.0, 249.0, 1.0]);
    expect(ThemeColour::resolve('var(--color-text-inverse)', ThemeColour::LIGHT))->toBe([248.0, 250.0, 252.0, 1.0]);
    expect(ThemeColour::resolve('var(--color-text-inverse)', ThemeColour::DARK))->toBe([15.0, 23.0, 42.0, 1.0]);
});

it('reports the Save button that shipped white on a token that turns white at night', function (): void {
    $report = colourPairsInTheSaveButtonAsItShipped();

    expect($report['failing'])->toHaveCount(1, 'The pre-fix markup has exactly one unreadable pairing.');
    expect($report['failing'][0])
        ->toContain('background: var(--color-text, #0f172a); color: #fff;')
        ->toContain('in dark')
        ->toContain('reads 1.10:1');
});

// The three shapes that are not defects, taken from the same fixture: a
// decorative fill with no text beside it, a colour over a background this file
// cannot see through, and a style the template hands over as a variable.
it('leaves alone a background with no text, a transparent ground and a style it cannot read', function (): void {
    $report = colourPairsInTheSaveButtonAsItShipped();
    $path = 'tests/Contracts/Fixtures/Contrast/a-save-button-drawn-in-hardcoded-white.blade';
    $source = (string) file_get_contents(base_path($path));

    expect(implode("\n", $report['failing']))->not->toContain('--color-amber');
    expect($report['transparent'])->toContain("{$path}:23  color: var(--color-text-muted, #64748b);");
    expect(ColourPairs::opaqueInlineStyles($path, $source))->toHaveCount(1);
});

// A style whose background is chosen by a condition chooses its text colour by
// the same condition. Crossing the branches would pair the emerald tint with
// the muted grey, which is a reading no render produces and would be the
// guard's first false positive.
it('pairs the branches of a condition rather than crossing them', function (): void {
    $report = colourPairsInTheSaveButtonAsItShipped();

    expect($report['measured'])->toBe(2, 'The Save button and the emerald branch of the year chip. '
        .'The other branch is transparent, and a crossed reading would make a third.');
    expect($report['failing'])->toHaveCount(1);
});

it('never lets a template declare a pair that vanishes in one theme', function (): void {
    $pairs = colourPairsInEveryTemplate();
    $report = ColourPairs::measure($pairs);

    expect($report['measured'])->toBeGreaterThan(15, 'The scan found almost nothing, so the pattern it '
        .'reads has changed and it is no longer measuring the tree.');

    expect($report['failing'])->toBe(
        [],
        "A background and a text colour declared together, below 4.5:1 in one of the two themes:\n  "
        .implode("\n  ", $report['failing'])
    );
});

it('never lets a rule declare a pair that vanishes in one theme', function (): void {
    $stylesheet = 'resources/css/app.css';
    $report = ColourPairs::measure(
        ColourPairs::inStylesheet($stylesheet, (string) file_get_contents(base_path($stylesheet)))
    );

    expect($report['measured'])->toBeGreaterThan(100, 'The scan found almost nothing, so the pattern it '
        .'reads has changed and it is no longer measuring the stylesheet.');

    expect($report['failing'])->toBe(
        [],
        "A rule that sets a background and a text colour, below 4.5:1 in a theme it renders in:\n  "
        .implode("\n  ", $report['failing'])
    );
});
