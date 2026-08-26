<?php

declare(strict_types=1);

// A money figure is the one thing on the dashboard that must be readable in
// full. Measured on an iPhone 12 mini with real transactions: €1,727.38 needed
// 170px of a 137px column and overflowed VISIBLY — the final 8 was painted
// under the "Breakdown" button. min-w-0 lets the column shrink, the button is
// shrink-0, and a formatted amount has no break opportunity inside it, so the
// row is the only thing that can give.

it('lets the net worth row wrap rather than overlap its own figure', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/net-worth-card.blade.php')
    );

    expect($blade)->toContain('flex flex-wrap items-start justify-between gap-4')
        ->and($blade)->not->toContain('<div class="flex items-start justify-between gap-4">');
});

// The button must stay shrink-0: shrinking it is what put its label on two
// lines in the languages that spell "Breakdown" longer.
it('keeps the breakdown button at its own width', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/net-worth-card.blade.php')
    );

    $start = strpos($blade, '<x-core::secondary-button');
    expect($start)->not->toBeFalse();

    expect(substr($blade, (int) $start, 200))->toContain('shrink-0');
});
