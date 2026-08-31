<?php

declare(strict_types=1);

// A counterparty card is ~290px wide on a phone and carries two amounts with
// their labels; a profile tab halves that again.

it('keeps an amount on the card from breaking mid-number', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // Without both halves the row broke inside a number: "€ 3.750," on one
    // line, "00" on the next.
    expect($css)->toContain(".cp-stats .value,\n    .cp-stats .label {\n        white-space: nowrap;\n    }")
        ->and($css)->toContain("    .cp-stats {\n        display: flex;\n        align-items: baseline;\n        flex-wrap: wrap;");
});

it('gives a profile tab one column on a phone', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $merchantTab = (string) file_get_contents(
        base_path('Modules/Counterparties/Resources/views/livewire/profile-tabs/merchant.blade.php'),
    );

    // An inline grid-template-columns cannot carry a breakpoint, which is how
    // the two cards stayed side by side at 390px and left a category name
    // touching its amount.
    expect($merchantTab)->not->toContain('grid-template-columns: 1fr 1fr')
        ->and($merchantTab)->toContain('class="cp-tab-duo"');

    expect($css)->toContain(".cp-tab-duo {\n        display: grid;\n        grid-template-columns: 1fr;")
        ->and($css)->toContain("@media (min-width: 640px) {\n        .cp-tab-duo {\n            grid-template-columns: 1fr 1fr;\n        }\n    }");
});

// Measured on the Samsung at the phone's own maximum font size: the triage
// queue scrolled sideways, 377px of content in a 347px shell. A fieldset
// carries min-width: min-content from the browser, so the block is a column of
// full-width controls now and every box in it is still told it may shrink.
it('lets the manual-label block and its name box shrink to the phone', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $triage = (string) file_get_contents(
        base_path('Modules/Counterparties/Resources/views/livewire/counterparty-triage.blade.php'),
    );

    expect($css)->toContain("    .triage-section {\n        display: flex;\n        flex-direction: column;\n        gap: var(--space-4);\n        min-width: 0;\n    }")
        ->and($css)->toContain("    .triage-decide {\n        display: block;\n        min-width: 0;")
        ->and($css)->toContain("    .triage-stack {\n        display: flex;\n        flex-direction: column;\n        gap: var(--space-2);\n        min-width: 0;\n    }");

    // The seven inline flex / padding / border declarations that opted this
    // section out of the grid every other screen uses are gone with it.
    $start = strpos($triage, 'class="triage-decide"');
    expect($start)->not->toBeFalse();

    $block = substr($triage, (int) $start, 2600);
    expect($block)->not->toContain('flex: 1 1 240px')
        ->and($block)->not->toContain('style="display: flex')
        ->and($block)->toContain('x-core::form-field');
});
