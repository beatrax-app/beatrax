<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-inline-font-size-below-16px
 */

// The controls iOS zooms the page for when their computed font-size is under
// 16px.
const AUTO_ZOOMING_FORM_CONTROLS = ['input', 'textarea', 'select'];

// The design tokens that resolve below the threshold. A control may size
// itself inline, but not with one of these.
const INLINE_FONT_SIZES_UNDER_16 = [
    'var(--text-xs)',
    'var(--text-xs, 12px)',
    'var(--text-sm)',
    'var(--text-sm, 13px)',
    'var(--text-base)',
    'var(--text-base, 15px)',
];

/**
 * The font-sizes in $style that land under the threshold. A named token is
 * matched literally; anything with a unit is compared, because the rule is
 * about what the browser computes and `font-size: 12px` is the same defect
 * spelled without a token.
 *
 * @return list<string>
 */
function inlineFontSizesUnderSixteen(string $style): array
{
    $under = [];

    foreach (PatternScan::all('/font-size:\s*([^;"]+)/i', $style)[1] as $declared) {
        $value = trim((string) $declared);

        if (in_array($value, INLINE_FONT_SIZES_UNDER_16, true)) {
            $under[] = $value;

            continue;
        }

        $unit = PatternScan::first('/^([0-9]*\.?[0-9]+)\s*(px|rem|em|pt|%)$/i', $value);

        if ($unit === []) {
            continue;
        }

        $number = (float) $unit[1];

        // 16px is the threshold; the others are the same size written
        // differently against a 16px root.
        $below = match (strtolower((string) $unit[2])) {
            'px' => $number < 16.0,
            'pt' => $number < 12.0,
            '%' => $number < 100.0,
            default => $number < 1.0,
        };

        if ($below) {
            $under[] = $value;
        }
    }

    return $under;
}

/**
 * @return list<string> `<tag> at <value>` for each control sized under the threshold
 */
function inlineFontSizeOffendersIn(string $source): array
{
    $offenders = [];

    foreach (MarkupSource::tags($source) as $element) {
        if (! in_array(strtolower($element->name), AUTO_ZOOMING_FORM_CONTROLS, true)) {
            continue;
        }

        foreach (inlineFontSizesUnderSixteen($element->attribute('style') ?? '') as $value) {
            $offenders[] = '<'.$element->name.'> at '.$value;
        }
    }

    return $offenders;
}

it('never sizes a form control inline below the iOS auto-zoom threshold', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    // Read off parsed elements rather than `<(input|textarea|select)\b[^>]*?
    // style="…"`. That pattern reached one styled control in this tree; the
    // lexer reaches twelve, including the multi-line `<textarea style="…">`
    // whose declaration list a single-line window can never span.
    expect(count($views))->toBeGreaterThan(
        150,
        'RepoTree returned '.count($views).' Blade views, which is too few to have read the tree.'
    );

    $controls = 0;
    $offenders = [];

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::tags($source) as $element) {
            if (in_array(strtolower($element->name), AUTO_ZOOMING_FORM_CONTROLS, true)) {
                $controls++;
            }
        }

        foreach (inlineFontSizeOffendersIn($source) as $offender) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).': '.$offender;
        }
    }

    // Read before the verdict: the floor sits far under today's 120 controls,
    // so a walk that parsed none fails here rather than reporting a clean tree.
    expect($controls)->toBeGreaterThan(
        50,
        'the walk found '.$controls.' form controls, which is too few to be this tree.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'an inline font-size under 16px defeats the coarse-pointer floor and makes iOS zoom the page on focus:',
        ...$offenders,
    ]));
});

// Every inline font-size in the tree is exactly 16px, so this rule reports on
// what it cannot find. The reader is driven against planted markup instead, and
// the near-misses are the three shapes the tree really carries: the threshold
// itself, an unrelated declaration, and the same token on something that is not
// a form control.
it('tells a control sized under the threshold from one sized at it', function (): void {
    expect(inlineFontSizeOffendersIn('<input style="font-size: var(--text-sm);">'))
        ->toBe(['<input> at var(--text-sm)'])
        ->and(inlineFontSizeOffendersIn('<textarea style="font-size: 12px;"></textarea>'))
        ->toBe(['<textarea> at 12px'])
        ->and(inlineFontSizeOffendersIn('<select style="font-size: 0.875rem;"></select>'))
        ->toBe(['<select> at 0.875rem'])
        ->and(inlineFontSizeOffendersIn('<input style="font-size: 16px;">'))->toBe([])
        ->and(inlineFontSizeOffendersIn('<input style="font-variant-numeric: tabular-nums;">'))->toBe([])
        ->and(inlineFontSizeOffendersIn('<span style="font-size: 12px;"></span>'))->toBe([]);
});
