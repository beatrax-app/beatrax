<?php

declare(strict_types=1);

use Tests\Contracts\Support\UnlayeredCss;

// Measured on an iPhone 12 mini (375pt) with iOS Larger Accessibility Sizes on
// and the slider at maximum — AX5, which puts 53px on :root and therefore on
// every rem in the product. Fifteen of eighteen authenticated routes scrolled
// sideways, `main` being the box that scrolled and not the document:
//
//   /reports 343  /              320  /notifications 280  /transactions 185
//   /reconcile 170 /settings     165  /uncategorized 138  /community    122
//   /calendar  110 /cash          72  /goals          69  /pots          40
//   /tax        34 /counterparties 26 /data-devices    22
//
// Three shapes account for all fifteen, and each fix is a no-op wherever the
// content already fits — which is every standard text size:
//
//   a label that cannot break     white-space: normal on the control
//   a row that cannot wrap        flex-wrap on the row
//   a gutter that grew with text  padding-inline from the px token
//
// Font sizes are untouched on purpose. The reader asked for 53px text; giving
// them less of it is not a fix.

/** @return list<string> every class list in $source, markup attribute or merged array value */
function accessibilityReflowClassAttributes(string $source): array
{
    preg_match_all('/class="([^"]*)"/', $source, $attributes);
    preg_match_all('/\'class\'\s*=>\s*"([^"]*)"/', $source, $merged);

    return array_merge($attributes[1], $merged[1]);
}

function accessibilityReflowHasWrappingRow(string $relativePath, string ...$tokens): bool
{
    $source = (string) file_get_contents(base_path($relativePath));

    foreach (accessibilityReflowClassAttributes($source) as $classes) {
        $present = preg_split('/\s+/', trim($classes)) ?: [];
        if (in_array('flex-wrap', $present, true) && array_diff($tokens, $present) === []) {
            return true;
        }
    }

    return false;
}

it('lets a control break its own label rather than size the page', function (): void {
    // Anchored on .chip-count rather than on `button,` — the 44px touch
    // floor two rules above opens with the same three selectors.
    $rule = UnlayeredCss::ruleWith("    .chip-count,\n    .cp-stats .value,");

    expect($rule)->not->toBeNull('No unlayered rule releases a control label; a layered one loses to whitespace-nowrap.');

    $missing = [];

    // The three that measured worst were a button, a Flux radio and a pill.
    foreach (["[role='radio']", "[role='tab']", '.status-pill', '.type-chip', '.chip-count', 'white-space: normal'] as $part) {
        if (! str_contains((string) $rule, $part)) {
            $missing[] = $part;
        }
    }

    expect($missing)->toBe([], 'The label rule does not reach: '.implode(', ', $missing));
});

it('lets an amount and a date break, which the prose rule never reached', function (): void {
    $rule = UnlayeredCss::ruleWith("    span,\n    a,\n    label,");

    expect($rule)->not->toBeNull('No unlayered rule releases the runs inside a control.');

    $missing = [];

    foreach (['td', 'th', 'overflow-wrap: anywhere'] as $part) {
        if (! str_contains((string) $rule, $part)) {
            $missing[] = $part;
        }
    }

    expect($missing)->toBe([], 'The run rule does not reach: '.implode(', ', $missing));
});

// A gutter in rems is a gutter that grows with the reader's text. Nested three
// deep on /community it left the innermost box a content width of zero.
it('measures a gutter in the px the spacing tokens are written in', function (): void {
    foreach (['3', '4', '6', '8'] as $step) {
        $rule = UnlayeredCss::ruleAt("    .p-{$step},\n    .px-{$step} {");

        expect($rule)->not->toBeNull("No coarse-pointer inline-padding cap for p-{$step}/px-{$step}.")
            ->and((string) $rule)->toContain("padding-inline: var(--space-{$step})");
    }
});

it('keeps the rows that had to wrap wrapping', function (): void {
    $rows = [
        'Modules/Pots/Resources/views/livewire/pots-page.blade.php' => ['items-start', 'justify-between'],
        'Modules/Goals/Resources/views/livewire/goals-page.blade.php' => ['items-start', 'justify-between'],
        'Modules/Recurring/Resources/views/livewire/fixed-payments-card.blade.php' => ['items-baseline', 'justify-between'],
        'Modules/Notifications/Resources/views/livewire/partials/notification-row.blade.php' => ['items-start', 'gap-3'],
        'Modules/Auth/Resources/views/livewire/app-lock-settings-section.blade.php' => ['items-center', 'justify-between'],
        'Modules/Reports/Resources/views/livewire/report-builder.blade.php' => ['items-baseline', 'justify-between'],
        'Modules/Core/Resources/views/components/date-input.blade.php' => ['items-center', 'justify-between'],
        'Modules/Ledger/Resources/views/livewire/transactions-list.blade.php' => ['items-center', 'gap-3'],
        'Modules/Calendar/Resources/views/livewire/calendar-page.blade.php' => ['items-center', 'gap-2'],
        'Modules/Shell/Resources/views/livewire/spending-trend-card.blade.php' => ['items-baseline', 'justify-end'],
        'Modules/DriftAlerts/Resources/views/livewire/drift-watch-page.blade.php' => ['items-baseline', 'justify-between'],
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php' => ['shrink-0', 'items-center', 'gap-1'],
        'Modules/Budgets/Resources/views/livewire/budgets-page.blade.php' => ['shrink-0', 'items-center', 'gap-1'],
    ];

    $offenders = [];
    foreach ($rows as $path => $tokens) {
        if (! accessibilityReflowHasWrappingRow($path, ...$tokens)) {
            $offenders[] = $path.' — '.implode(' ', $tokens);
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These rows lost the wrap that kept them on the screen:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

// Page overflow is not the only way a layout fails at these sizes: a box can
// measure 0 past the screen and still be 4px wide with its text running one
// glyph per line down the page. Both of these were found that way, by walking
// for leaf boxes narrower than a few characters rather than by the width of
// <main>.
it('never buys the fit by squeezing a box to one glyph per line', function (): void {
    $rule = UnlayeredCss::ruleWith('    .flex-1 {');

    expect($rule)->not->toBeNull('No coarse-pointer basis for a flexible item.')
        ->and((string) $rule)->toContain('flex-basis: auto');
});

it('keeps a restacked row wide enough to read its own subject', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain('--restacked-row-subject: minmax(min(100%, 8rem), 1fr);');

    // Every table that restacks reads the track from the token; a bare
    // `minmax(0, 1fr)` beside an `auto` is the shape that had no floor.
    expect(substr_count($css, 'grid-template-columns: var(--restacked-row-subject) max-content;'))->toBe(4)
        ->and($css)->not->toContain('grid-template-columns: minmax(0, 1fr) auto;')
        ->and($css)->not->toContain('grid-template-columns: var(--restacked-row-subject) auto;');
});

// shrink-0 is deliberate on all three: a pill is one short word and a page
// action should not be squeezed beside a heading. max-w-full leaves that alone
// and caps the used width, so the label wraps inside a control that still does
// not shrink.
it('caps the three controls whose shrink-0 is load-bearing', function (): void {
    $capped = [
        'Modules/Core/Resources/views/components/status-pill.blade.php',
        'Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php',
        'Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php',
    ];

    $offenders = [];
    foreach ($capped as $path) {
        $source = (string) file_get_contents(base_path($path));
        $found = false;
        foreach (accessibilityReflowClassAttributes($source) as $classes) {
            $present = preg_split('/\s+/', trim($classes)) ?: [];
            if (in_array('max-w-full', $present, true) && in_array('shrink-0', $present, true)) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These controls kept shrink-0 without the cap that lets the label wrap:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
