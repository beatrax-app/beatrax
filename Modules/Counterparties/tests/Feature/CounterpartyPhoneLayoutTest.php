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
