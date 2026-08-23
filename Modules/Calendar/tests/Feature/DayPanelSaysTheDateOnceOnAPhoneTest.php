<?php

declare(strict_types=1);

// Read on an iPhone 12 mini with an approved series on the day: tapping a
// calendar cell opened the bottom sheet with the date printed TWICE, stacked —
// "24 Aug" at 15.9px from the sheet's own title, then "24 Aug 2026" at 17px
// from the day panel's header. The desktop right rail includes the same partial
// with no heading above it, which is why only the phone showed the pair.

it('gives the phone sheet the full date and stops the panel repeating it', function (): void {
    $page = (string) file_get_contents(
        base_path('Modules/Calendar/Resources/views/livewire/calendar-page.blade.php')
    );

    $start = strpos($page, '<x-core::bottom-sheet name="day-detail"');
    expect($start)->not->toBeFalse('The calendar no longer wraps the day panel in a sheet.');

    $sheet = substr($page, (int) $start, 400);

    expect($sheet)->toContain("translatedFormat('j M Y')")
        ->and($sheet)->not->toContain("translatedFormat('j M')\"")
        ->and($sheet)->toContain("'showDate' => false");
});

// The rail has no heading of its own, so the partial must still print the date
// when nobody says otherwise.
it('still prints the date for the desktop right rail', function (): void {
    $panel = (string) file_get_contents(
        base_path('Modules/Calendar/Resources/views/livewire/partials/day-panel.blade.php')
    );

    expect($panel)->toContain('@if ($showDate ?? true)')
        ->and($panel)->toContain("translatedFormat('j M Y')");
});

// Measured in the sheet: 50x16 and 92x16, and a tap 9px outside reached them
// at 0 of 6 positions. Counted against the anchors actually in the partial
// rather than against a number, so a link added later cannot ship without one.
it('gives every drill-through link a reachable target', function (): void {
    $panel = (string) file_get_contents(
        base_path('Modules/Calendar/Resources/views/livewire/partials/day-panel.blade.php')
    );

    $anchors = substr_count($panel, '<a'.PHP_EOL);

    expect($anchors)->toBeGreaterThan(0)
        ->and(substr_count($panel, 'class="tap-link font-medium underline-offset-2 hover:underline"'))->toBe($anchors);
});
