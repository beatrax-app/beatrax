<?php

declare(strict_types=1);

// The floor names every element that can be a button except the one an action
// is often marked up as. Measured on an iPhone 12 mini: the report library's
// own "Build a new report" — an <a> wearing .pill-btn-primary — answered a
// finger over 36px, while the identical control drawn as a <button> answered
// over 44. The header links beside a page title were worse: 16-23px, because
// nothing gave them the band .tap-link exists to give.

it('puts a link drawn as a pill button on the same touch floor as a button', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, "label:has(> input[type='file']),");
    expect($start)->not->toBeFalse('No coarse-pointer floor lists the file-picker label.');

    $rule = substr($css, (int) $start, 200);

    expect($rule)->toContain('.pill-btn-primary')
        ->and($rule)->toContain('.pill-btn-ghost')
        ->and($rule)->toContain('min-height: 44px;');
});

it('gives every standalone action link beside a page title its 44px band', function (): void {
    $blades = [
        'Modules/Chains/Resources/views/livewire/chain-hints-queue.blade.php',
        'Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php',
        'Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php',
        'Modules/DriftAlerts/Resources/views/livewire/drift-watch-page.blade.php',
        'Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php',
        'Modules/Notifications/Resources/views/livewire/notifications-page.blade.php',
        'Modules/Tax/Resources/views/livewire/tax-page.blade.php',
    ];

    $without = [];
    foreach ($blades as $blade) {
        if (! str_contains((string) file_get_contents(base_path($blade)), 'tap-link')) {
            $without[] = $blade;
        }
    }

    expect($without)->toBe([], 'No 44px band on: '.implode(', ', $without));
});
