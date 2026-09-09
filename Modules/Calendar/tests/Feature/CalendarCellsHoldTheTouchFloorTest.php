<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

// Seven columns and a fixed 16px gutter either side: at the Samsung's largest
// display size the viewport is 320px, the grid got 287 of it and every one of
// the 42 cells came out 41px wide. The grid needs 308 to give each cell 44.
//
// It used to take that width by narrowing the whole page to px-1, which took
// the heading and the prose with it — twelve pixels left of every other title
// in the application. The frame takes the gutter back for itself now, and this
// asks for the width rather than for either spelling of it: any arrangement
// that leaves 308px for the grid on a 320px viewport satisfies the cell floor.

const NARROWEST_PHONE = 320;

const TOUCH_FLOOR = 44;

const CALENDAR_COLUMNS = 7;

/** Tailwind's spacing scale in pixels, for the base-breakpoint utilities only. */
function calendarSpacing(string $classes, string $prefix): int
{
    foreach (explode(' ', $classes) as $class) {
        if (str_starts_with($class, $prefix)) {
            return (int) round(((float) substr($class, strlen($prefix))) * 4);
        }
    }

    return 0;
}

it('gives the month grid enough width for a 44px cell on the narrowest phone', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Calendar/Resources/views/livewire/calendar-page.blade.php'),
    );

    $divs = MarkupSource::elements($blade, 'div');

    // Named rather than taken by position: the page root is the container that
    // sets the width, and a wrapper added above it would silently become the
    // thing this rule measures.
    $pages = array_values(array_filter(
        $divs,
        static fn ($div): bool => str_contains($div->attribute('class') ?? '', 'mx-auto max-w-'),
    ));

    $frames = array_values(array_filter(
        $divs,
        static fn ($div): bool => str_contains($div->attribute('class') ?? '', 'cal-grid-frame'),
    ));

    expect($pages)->not->toBe([], 'The calendar page has no width container to read.');
    expect($frames)->not->toBe([], 'The month grid has no .cal-grid-frame wrapper to read.');

    $page = $pages[0];

    $gutter = calendarSpacing($page->attribute('class') ?? '', 'px-');
    $reclaimed = calendarSpacing($frames[0]->attribute('class') ?? '', '-mx-');

    $grid = NARROWEST_PHONE - 2 * $gutter + 2 * $reclaimed;
    $cell = $grid / CALENDAR_COLUMNS;

    expect($cell)->toBeGreaterThanOrEqual(TOUCH_FLOOR, implode("\n", [
        sprintf('A day cell is %.1fpx on a %dpx viewport, under the %dpx touch floor.', $cell, NARROWEST_PHONE, TOUCH_FLOOR),
        sprintf('The page gutter takes %dpx a side and the frame gives %dpx back.', $gutter, $reclaimed),
        '',
        'Either give the frame more back, or make the cells narrower somewhere',
        'the reader does not have to hit them. Narrowing the page gutter is the',
        'answer this used to take, and it moved the heading off the margin every',
        'other screen starts at — EveryPageStartsAtTheSameMarginArchTest holds',
        'that line now.',
    ]));
});

// The arithmetic above is only worth anything if it can fail. This is the
// arrangement that shipped the 41px cell: the app's own gutter and nothing
// given back.
it('reads a page gutter with nothing reclaimed as too narrow', function (): void {
    $gutter = calendarSpacing('mx-auto max-w-7xl px-4 py-6', 'px-');
    $reclaimed = calendarSpacing('cal-grid-frame', '-mx-');

    expect($gutter)->toBe(16)
        ->and($reclaimed)->toBe(0)
        ->and((NARROWEST_PHONE - 2 * $gutter + 2 * $reclaimed) / CALENDAR_COLUMNS)
        ->toBeLessThan(TOUCH_FLOOR);
});
