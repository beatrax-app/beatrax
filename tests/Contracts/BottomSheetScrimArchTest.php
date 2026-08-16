<?php

declare(strict_types=1);

/*
 * The bottom-sheet scrim is a fixed, full-viewport, z-50 layer whose
 * visibility belongs to Alpine's x-show. A `display` declaration in the phone
 * media query overrides x-show, and `!important` beat even the inline style —
 * so every phone-width page carrying a sheet sat under an invisible overlay
 * that swallowed taps while scrolling still worked. It reads as a frozen app.
 */

it('never forces the bottom-sheet scrim visible from CSS', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.bottom-sheet-scrim {');

    expect($start)->toBeInt('the scrim rule must exist');

    $end = strpos($css, '}', $start);
    $rule = substr($css, $start, $end - $start);

    expect($rule)->not->toContain('display:', 'x-show owns the scrim\'s visibility, not this rule');
});

it('keeps the scrim a full-screen layer so an open sheet still blocks the page', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.bottom-sheet-scrim {');
    $rule = substr($css, (int) $start, (int) strpos($css, '}', (int) $start) - (int) $start);

    // Removing the display override must not have cost the scrim its job.
    expect($rule)->toContain('position: fixed')
        ->and($rule)->toContain('inset: 0');
});
