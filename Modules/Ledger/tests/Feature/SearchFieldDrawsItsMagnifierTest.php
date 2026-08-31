<?php

declare(strict_types=1);

// Measured on an iPhone 12 mini: ⌕ (U+2315) has no glyph in the body stack and
// the fallback drew it at roughly 9x9 of ink beside 15px placeholder text, on
// the same screen as the top bar's drawn magnifier.

it('draws the search field magnifier instead of typing the character', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Ledger/Resources/views/livewire/partials/search-toolbar.blade.php')
    );

    expect($blade)->toContain('<x-core::search-mark')
        ->and($blade)->not->toContain('⌕');
});

it('draws the same mark in the mobile top bar', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Core/Resources/views/components/mobile-top-bar.blade.php')
    );

    expect($blade)->toContain('<x-core::search-mark');
});

// Every box below is a search field on a phone: one mark standing alone ahead
// of a placeholder, which is the case the component exists for. The drawer's
// and the palette's each measured 8-17px of advance with about 9x9 of ink,
// beside a 16px input, on the same screens as the top bar's drawn mark.
it('draws the same mark in every search box a phone can reach', function (string $path): void {
    $blade = (string) file_get_contents(base_path($path));

    expect($blade)->toContain('<x-core::search-mark')
        ->and($blade)->not->toContain('⌕');
})->with([
    'counterparties' => ['Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php'],
    'navigation drawer' => ['Modules/Shell/Resources/views/livewire/app-sidebar.blade.php'],
    'phone filter row' => ['Modules/Core/Resources/views/components/filter-sheet-trigger.blade.php'],
    'command palette' => ['Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php'],
]);

// The palette's mark and its spinner swap in and out of one slot, so a
// difference in box would shift the query text sideways as a search starts.
it('keeps the palette mark on the same box as the spinner it swaps with', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php')
    );

    $start = (int) strpos($blade, '<x-core::search-mark');

    expect(substr($blade, $start, 200))->toContain('size="md"');
    expect((string) file_get_contents(
        base_path('Modules/Core/Resources/views/components/spinner.blade.php')
    ))->toContain("'md'");
});

it('leaves the character where it sits in a column of other characters', function (): void {
    $navigation = (string) file_get_contents(
        base_path('Modules/Shell/Public/Navigation/AppNavigation.php')
    );

    expect($navigation)->toContain('⌕');
});
