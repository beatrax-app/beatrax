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

it('keeps the two amounts together so they wrap as one', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/spending-trend-card.blade.php')
    );

    expect($blade)->toContain('<span class="ml-auto flex shrink-0 items-baseline gap-x-3">');
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
