<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A zero-decimal base currency writes the whole figure in digits — ¥1,039,075,
// ten characters with nothing to round off — and the sm band gives a day cell
// 87px. Day number and balance corner shared one line with no gap and no way to
// shrink, so the cell read "31¥1,039,075" and the amount crossed the cell's
// right padding by two pixels into the neighbouring day.

function ayCalendarSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

it('lets the balance corner leave the day number\'s line when the two do not fit', function (): void {
    $blade = ayCalendarSource('Modules/Calendar/Resources/views/livewire/calendar-page.blade.php');

    expect($blade)->toContain('class="cal-day-head justify-center sm:justify-between"');

    $css = ayCalendarSource('resources/css/app.css');
    $head = PatternScan::first('/\.cal-day-head \{([^}]*)\}/', $css);

    expect($head)->not->toBeEmpty('.cal-day-head has no rule in app.css');
    expect($head[1])->toContain('flex-wrap: wrap')
        ->and($head[1])->toContain('gap: 0 var(--space-1');
});

it('clips a figure wider than the whole cell inside it rather than over the next day', function (): void {
    $css = ayCalendarSource('resources/css/app.css');
    $balance = PatternScan::first('/\.cal-day-balance \{([^}]*)\}/', $css);

    expect($balance)->not->toBeEmpty('.cal-day-balance has no rule in app.css');
    expect($balance[1])->toContain('max-width: 100%')
        ->and($balance[1])->toContain('text-overflow: ellipsis');
});
