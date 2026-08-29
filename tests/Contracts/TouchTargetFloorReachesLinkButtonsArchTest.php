<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

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
        'Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php',
        'Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php',
        'Modules/DriftAlerts/Resources/views/livewire/drift-watch-page.blade.php',
        'Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php',
        'Modules/Notifications/Resources/views/livewire/notifications-page.blade.php',
        'Modules/Sync/Resources/views/livewire/devices-and-sync-settings-section.blade.php',
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

// The switch track is 44x26 by design, and twenty callers draw it. Growing it
// to 44 tall would make a pill the size of a button, so it takes the band
// .tap-link takes instead -- same shape of problem, wide enough already and
// short only in height. Measured over 35px on an iPhone 12 mini before this.
it('gives the switch track the same band, since it is 44 wide and 26 tall', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $selector = ".tap-link::after,\n    .switch::after,\n    td > a:only-child::after {";
    $band = CssRule::blockFor($css, $selector);

    expect($band)->not->toBe('', 'The switch no longer shares the band .tap-link gets.')
        ->and($band)->toContain('height: 44px;')
        // A band anchored to one edge takes its width from the control, which
        // is how a 42px "Hints →" and a 29px "Etos" got through.
        ->and($band)->toContain('min-width: 44px;')
        ->and($band)->toContain('right: auto;')
        ->and(CssRule::blockFor($css, ".tap-link,\n    .switch,\n    td > a:only-child {"))
        ->toContain('position: relative;');
});
