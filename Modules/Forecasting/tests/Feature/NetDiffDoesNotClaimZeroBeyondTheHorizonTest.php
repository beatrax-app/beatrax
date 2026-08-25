<?php

declare(strict_types=1);

use Modules\Core\Public\Support\Lang;

// The strip carries five checkpoints -- 30, 60, 90, 180, 365 -- and computes
// them from the horizon currently selected. Every checkpoint beyond that
// horizon was initialised to 0 and left there, so at horizon 90 the screen
// printed "EUR0.00 at day 365" while this app's own completed 365-day run held
// +EUR500.00. Announced to a screen reader, that zero read as "equal to
// baseline": not a blank, a claim, and the wrong one.
it('draws a checkpoint the loaded run cannot reach as unknown, not as zero', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Forecasting/Resources/views/livewire/partials/net-diff-tile.blade.php'),
    );

    expect($blade)->toContain('$unknown');
    expect($blade)->toContain('forecasting::forecast.net_diff_unknown');

    // The em dash is the whole point: a formatted zero here is indistinguishable
    // from a real zero difference, which is a state the strip must also express.
    expect($blade)->toContain("'—'");
});

it('initialises every checkpoint to unknown rather than to nothing-changed', function (): void {
    $source = (string) file_get_contents(
        base_path('Modules/Forecasting/Internal/Http/Livewire/Concerns/BuildsForecastCharts.php'),
    );

    expect($source)->toContain('$result[$horizonKey] = null;');
    expect($source)->not->toContain('$result[$horizonKey] = 0;');
});

it('says so in words, not only by drawing a dash', function (): void {
    $sentence = Lang::get('forecasting::forecast.net_diff_unknown');

    expect($sentence)->not->toBe('forecasting::forecast.net_diff_unknown');
    expect($sentence)->not->toBe(Lang::get('forecasting::forecast.equal_to_baseline'));
});
