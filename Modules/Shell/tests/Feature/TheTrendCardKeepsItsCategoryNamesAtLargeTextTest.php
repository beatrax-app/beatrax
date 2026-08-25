<?php

declare(strict_types=1);

// Each mover row was one nowrap flex line: a `min-w-0 flex-1 truncate` name and
// two amounts that could not shrink, the delta pinned to `w-20`. w-20 is 5rem,
// and :root takes -apple-system-body, so at the largest Dynamic Type the phone
// offers it is 115px rather than 80. Measured on an iPhone 12 mini with the
// slider at maximum: the row is 212px, its two amounts took 89 + 115 with 34px
// of gap between them, and the name was handed 0px. `truncate` is
// overflow: hidden, so all six categories rendered as nothing at all and the
// card read as a column of amounts belonging to nobody.

it('lets the amounts drop below the category name rather than squeeze it out', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/spending-trend-card.blade.php')
    );

    expect($blade)->toContain('<li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-sm">')
        ->and($blade)->not->toContain('<li class="flex items-center justify-between gap-3 text-sm">');
});

// The pair still travels as one — it is a single flex item of the row above,
// so it lands on the name's line or on its own, never split across the two.
// What changed is what happens once it HAS a line of its own and still does not
// fit: measured at the iOS accessibility sizes, a 53px root makes that pair
// 505px — €1,499.99 at 200, −€212.36 at 265, 40 of gap — on a 375px display.
// Held together it put / 191px past the screen; allowed to wrap inside itself
// it measures 0, with the delta under the amount it belongs to rather than
// beside it.
it('keeps the two amounts together until neither of them fits', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/spending-trend-card.blade.php')
    );

    expect($blade)->toContain('<span class="ml-auto flex flex-wrap items-baseline justify-end gap-x-3">');
});

it('gives the name a basis to wrap from, not just room to vanish into', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/spending-trend-card.blade.php')
    );

    $start = strpos($blade, '$mover->name');
    expect($start)->not->toBeFalse();

    $nameSpan = substr($blade, max(0, (int) $start - 220), 220);

    expect($nameSpan)->toContain('basis-32');
});
