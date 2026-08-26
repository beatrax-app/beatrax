<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// Step 6's per-source eyebrow is three spans in one flex row. At phone width
// every span takes the reflow block's `overflow-wrap: anywhere`, which drops
// min-content to a single character, and the row did not wrap -- so all three
// were squeezed until their text broke: "FROM YOUR BANK STATEMENT" over three
// lines, and "· ✓ READY" rendered as "READ" over "Y" in 42px.

it('lets the eyebrow wrap where it declares the row it wraps', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $rule = CssRule::blockFor($css, '.preview-section-eyebrow {');

    expect($rule)->toContain('display: flex;')
        ->and($rule)->toContain('flex-wrap: wrap;');
});

it('keeps its parts at the width of their longest word on a coarse pointer', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $rule = CssRule::blockFor($css, '.preview-section-eyebrow > span');

    expect($rule)->toContain('overflow-wrap: break-word;')
        ->and($rule)->not->toContain('anywhere');

    // Unlayered, or the blanket span rule it overrides outranks it whatever
    // the specificity, and inside the block that sets that rule in the first
    // place.
    expect(CssRule::atRuleEnclosing($css, '.preview-section-eyebrow > span'))->toContain('pointer: coarse');
});

it('still has the three spans the rule is written for', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Onboarding/Resources/views/components/consolidated-preview-section.blade.php')
    );

    $start = strpos($blade, 'preview-section-eyebrow');
    expect($start)->not->toBeFalse('The first-import step no longer draws an eyebrow.');

    $end = strpos($blade, '</p>', (int) $start);
    expect(substr_count(substr($blade, (int) $start, (int) $end - (int) $start), '<span'))->toBe(3);
});
