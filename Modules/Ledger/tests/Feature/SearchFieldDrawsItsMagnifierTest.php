<?php

declare(strict_types=1);

// Measured on an iPhone 12 mini: ⌕ (U+2315) has no glyph in Inter and the
// system fallback drew it at roughly 9x9 of ink beside 15px placeholder text,
// on the same screen as the top bar's drawn magnifier.

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

it('leaves the character where it sits in a column of other characters', function (): void {
    $sidebar = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/app-sidebar.blade.php')
    );

    expect($sidebar)->toContain('⌕');
});
