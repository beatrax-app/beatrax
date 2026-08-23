<?php

declare(strict_types=1);

// At the Samsung's largest display size the five horizon chips came to 322px in
// a 288px column and "365 days" ran from 271 to 333 on a 320px screen — the
// longest horizon was the one a reader could not reach. The row around the
// group already wraps; the group itself did not.
it('lets the horizon chips wrap rather than run off the screen', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php'),
    );

    $start = strpos($blade, 'role="radiogroup"');
    expect($start)->not->toBeFalse();

    $open = strrpos(substr($blade, 0, (int) $start), '<div ');
    expect($open)->not->toBeFalse();

    $group = substr($blade, (int) $open, (int) $start - (int) $open + 40);

    expect($group)->toContain('inline-flex')
        ->and($group)->toContain('flex-wrap');
});
