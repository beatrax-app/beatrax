<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
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

/**
 * @return list<string> every class list in $source, markup attribute or merged array value
 */
function accessibilityReflowClassAttributes(string $source): array
{
    $attributes = PatternScan::all('/class="([^"]*)"/', $source);
    $merged = PatternScan::all('/\'class\'\s*=>\s*"([^"]*)"/', $source);

    return array_merge($attributes[1], $merged[1]);
}

function accessibilityReflowHasWrappingRow(string $relativePath, string ...$tokens): bool
{
    return accessibilityReflowRowHasTokens((string) file_get_contents(base_path($relativePath)), ...$tokens);
}

/** @return bool whether any class list in $source wraps and carries every one of $tokens */
function accessibilityReflowRowHasTokens(string $source, string ...$tokens): bool
{
    foreach (accessibilityReflowClassAttributes($source) as $classes) {
        $present = PatternScan::split('/\s+/', trim($classes));
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
    // The thirteen rows measured past the screen at AX5, each with the class
    // pair that identifies it. A path here that no longer exists is a row whose
    // wrap nothing checks, so the file is read rather than assumed.
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

    $missing = array_values(array_filter(
        array_keys($rows),
        static fn (string $path): bool => ! is_file(base_path($path)),
    ));

    expect($missing)->toBe([], implode("\n  ", [
        'These templates no longer exist, so the wrap this rule pins is checked on nothing:',
        ...$missing,
    ]));

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

    expect(str_contains($css, '--restacked-row-subject: minmax(min(100%, 8rem), 1fr);'))->toBeTrue(
        'The restacked-row subject track has no floor token, so a subject column can be squeezed to nothing.'
    );

    // Every table that restacks reads the track from the token; a bare
    // `minmax(0, 1fr)` beside an `auto` is the shape that had no floor.
    //
    // The action track is minmax(0, max-content), not max-content: a bare
    // max-content cannot shrink, so on /rules the Dutch pair "Bewerken
    // Verwijderen" sized the row and pushed the destructive action 29px past
    // the screen -- reachable only by a horizontal drag nothing advertises.
    // Four tables restack; every one of them reads the track from the token.
    expect(substr_count($css, 'grid-template-columns: var(--restacked-row-subject) minmax(0, max-content);'))->toBe(
        4,
        'A restacking table no longer takes its subject track from the shared token, so its floor is whatever that one rule says.'
    );

    $shapesWithNoFloor = array_values(array_filter([
        'grid-template-columns: var(--restacked-row-subject) max-content;',
        'grid-template-columns: minmax(0, 1fr) auto;',
        'grid-template-columns: var(--restacked-row-subject) auto;',
    ], static fn (string $shape): bool => str_contains($css, $shape)));

    expect($shapesWithNoFloor)->toBe([], implode("\n  ", [
        'These restacked-row shapes are back in the stylesheet:',
        ...$shapesWithNoFloor,
        '',
        'A bare max-content cannot shrink, so on /rules the Dutch pair "Bewerken',
        'Verwijderen" sized the row and pushed the destructive action 29px past the',
        'screen — reachable only by a horizontal drag nothing advertises. An `auto`',
        'beside a bare minmax(0, 1fr) is the shape that had no floor at all.',
    ]));
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
            $present = PatternScan::split('/\s+/', trim($classes));
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

// A control breaks its label only where the word cannot fit alone. `anywhere`
// drops min-content to one character, so a flex row squeezed a button to 80px
// and rendered "Discard import" as "Discar / d / import" — at the default text
// size, not only at an accessibility one.
it('lets a control ask for its widest word before it breaks one', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    expect($css)->not->toBe('', 'The stylesheet is unreadable from this Composer root, so nothing below was measured.');

    // Walked back from each declaration to the selector list that owns it.
    // These rules sit inside @media, so top-level block matching finds the
    // media query instead; and a regex over a file this size stopped reading
    // once already, silently.
    $rules = [];
    $offset = 0;
    while (($at = strpos($css, 'overflow-wrap:', $offset)) !== false) {
        $offset = $at + 1;
        $open = strrpos(substr($css, 0, $at), '{');
        if ($open === false) {
            continue;
        }

        $before = substr($css, 0, $open);
        $stop = 0;
        foreach (['{', '}', ';', '*/'] as $boundary) {
            $found = strrpos($before, $boundary);
            if ($found !== false && $found + strlen($boundary) > $stop) {
                $stop = $found + strlen($boundary);
            }
        }

        $prelude = trim(substr($css, $stop, $open - $stop));
        $mode = trim(strtok(substr($css, $at + strlen('overflow-wrap:')), ';') ?: '');
        $rules[] = [array_map(trim(...), explode(',', $prelude)), $mode];
    }
    // Every overflow-wrap declaration in the sheet is walked back to the
    // selector list that owns it. A walk that found none of them would report
    // each selector below as having no rule, so the floor is read first.
    expect(count($rules))->toBeGreaterThan(
        3,
        'No overflow-wrap declaration was found at all, so this rule read nothing.'
    );

    foreach (['button', 'summary', "[role='button']", "[role='radio']", "[role='tab']"] as $selector) {
        $modes = [];
        foreach ($rules as [$selectors, $mode]) {
            if (in_array($selector, $selectors, true)) {
                $modes[] = $mode;
            }
        }

        expect($modes)->not->toBe([], $selector.' has no overflow-wrap rule at all.');
        expect(array_values(array_unique($modes)))->toBe(['break-word'],
            $selector.' must use break-word: anywhere lets a flex row split its label mid-word.');
    }
});

// Both pinned-row rules read their verdict off one class-list reader, and a
// reader that found nothing would report every row as having lost its wrap.
it('reads a wrapping row where there is one, and not where a token is missing', function (): void {
    $source = <<<'BLADE'
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h2>Pots</h2>
            <x-core::button>New pot</x-core::button>
        </div>
        <div {{ $attributes->merge(['class' => "flex items-center justify-between"]) }}>
            <span>No wrap here</span>
        </div>
        BLADE;

    expect(accessibilityReflowClassAttributes($source))->toBe([
        'flex flex-wrap items-start justify-between gap-2',
        'flex items-center justify-between',
    ]);

    expect(accessibilityReflowRowHasTokens($source, 'items-start', 'justify-between'))->toBeTrue();
    expect(accessibilityReflowRowHasTokens($source, 'items-center', 'justify-between'))->toBeFalse();
    expect(accessibilityReflowRowHasTokens($source, 'items-start', 'gap-3'))->toBeFalse();
});
