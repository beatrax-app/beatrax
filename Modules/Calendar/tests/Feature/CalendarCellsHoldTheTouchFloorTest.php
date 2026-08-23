<?php

declare(strict_types=1);

// Seven columns and a fixed 16px gutter either side: at the Samsung's largest
// display size the viewport is 320px, the grid got 287 of it and every one of
// the 42 cells came out 41px wide. Four pixels of gutter below sm gives the
// grid 311 and every cell exactly 44; from 640px up nothing changes.
it('gives the month grid enough width for a 44px cell on the narrowest phone', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Calendar/Resources/views/livewire/calendar-page.blade.php'),
    );

    expect($blade)->toContain('class="mx-auto max-w-7xl px-1 sm:px-4 py-12"')
        ->and($blade)->not->toContain('class="mx-auto max-w-7xl px-4 py-12"');
});
