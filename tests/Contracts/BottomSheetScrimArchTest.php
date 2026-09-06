<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-display-override-on-the-bottom-sheet-scrim
 */

/**
 * The body of the first rule whose selector line opens with $selector, or null
 * when the stylesheet declares none. Read to the first closing brace, which is
 * as far as a flat declaration block goes.
 */
function scrimRuleBody(string $css, string $selector): ?string
{
    $start = strpos($css, $selector);

    if ($start === false) {
        return null;
    }

    $end = strpos($css, '}', $start);

    return $end === false ? null : substr($css, $start, $end - $start);
}

/** The alpha of the first rgba() a rule paints with, or null when it paints none. */
function scrimRuleAlpha(string $rule): ?float
{
    $match = PatternScan::first('/rgba\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*,\s*([\d.]+)\s*\)/', $rule);

    return isset($match[1]) ? (float) $match[1] : null;
}

// Read once and handed to each case: the stylesheet is the subject of all three,
// and a rule missing from it is a case that asserted nothing.
function scrimStylesheet(): string
{
    return (string) file_get_contents(base_path('resources/css/app.css'));
}

it('never forces the bottom-sheet scrim visible from resources/css/app.css', function (): void {
    $rule = scrimRuleBody(scrimStylesheet(), '.bottom-sheet-scrim {');

    expect($rule)->not->toBeNull('resources/css/app.css declares no .bottom-sheet-scrim rule, so this case read nothing.');

    expect($rule)->not->toContain('display:');
});

it('keeps the scrim a full-screen layer so an open sheet still blocks the page', function (): void {
    $rule = (string) scrimRuleBody(scrimStylesheet(), '.bottom-sheet-scrim {');

    // Removing the display override must not have cost the scrim its job.
    expect($rule)->toContain('position: fixed')
        ->and($rule)->toContain('inset: 0');
});

it('dims the page behind a modal exactly as much as behind the sheet', function (): void {
    // Flux's own backdrop was 10% black. Beside a 40% sheet scrim the same page
    // was covered twice at two different strengths, and on a phone the fainter
    // one barely read as modal at all. Compared rather than spelled out: a
    // literal pinned here would let the scrim move away from the backdrop and
    // still pass, which is the drift the override exists to close.
    $css = scrimStylesheet();

    $backdrop = scrimRuleBody($css, '[data-flux-modal] > dialog[open]::backdrop {');
    expect($backdrop)->not->toBeNull('nothing in app.css overrides the Flux backdrop');

    $scrim = scrimRuleBody($css, '.bottom-sheet-scrim {');
    expect($scrim)->not->toBeNull('app.css declares no .bottom-sheet-scrim rule to compare the backdrop against');

    $backdropAlpha = scrimRuleAlpha((string) $backdrop);
    $scrimAlpha = scrimRuleAlpha((string) $scrim);

    expect($backdropAlpha)->not->toBeNull('the Flux backdrop override paints with no rgba(), so there is no strength to compare');
    expect($scrimAlpha)->not->toBeNull('the sheet scrim paints with no rgba(), so there is no strength to compare');

    expect($backdropAlpha)->toBe(
        $scrimAlpha,
        'The modal backdrop and the bottom-sheet scrim cover the same page and must dim it by the same '
        .'amount: two strengths on one screen read as one of them being a mistake, and on a phone the '
        .'fainter one does not read as modal at all.',
    );
});

// Both readers above answer null on a stylesheet that stopped declaring the
// rule, which is the same answer a clean sheet gives. These pin the difference.
it('tells a rule that is absent from one that is merely clean', function (): void {
    $sheet = ".other { color: red; }\n.probe {\n    position: fixed;\n    background: rgba(15, 23, 42, 0.35);\n}\n";

    expect(scrimRuleBody($sheet, '.probe {'))->toContain('position: fixed');
    expect(scrimRuleBody($sheet, '.missing {'))->toBeNull('a selector the sheet does not declare must read as absent, not as an empty rule');
    expect(scrimRuleAlpha((string) scrimRuleBody($sheet, '.probe {')))->toBe(0.35);
    expect(scrimRuleAlpha('.probe { color: red; }'))->toBeNull('a rule painting no rgba() must read as having no strength');
});
