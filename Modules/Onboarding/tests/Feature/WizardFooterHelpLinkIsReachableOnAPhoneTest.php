<?php

declare(strict_types=1);

// The wizard footer's help link is a standalone control, not a link inside a
// sentence, so the coarse-pointer exemption for inline links does not cover it.
// Measured on an iPhone 12 mini across all nine steps: 67x19, and a tap 5px
// outside the box reached it at 0 of 20 positions.

it('gives the wizard help link a 44px band on a coarse pointer', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Onboarding/Resources/views/livewire/setup-wizard.blade.php')
    );

    $start = strpos($blade, 'wiz-help-link');
    expect($start)->not->toBeFalse('The wizard footer no longer carries a help link.');

    expect(substr($blade, (int) $start, 40))->toContain('tap-link');
});

it('backs that band with a rule the phone actually applies', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.tap-link::after');
    expect($start)->not->toBeFalse('.tap-link declares no halo.');

    $rule = substr($css, (int) $start, 260);

    expect($rule)->toContain('height: 44px;')
        ->and($rule)->toContain('left: 0;')
        ->and($rule)->toContain('right: 0;');
});
