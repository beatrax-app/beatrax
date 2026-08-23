<?php

declare(strict_types=1);

// A 44px floor that names only buttons leaves the control a finger reaches most
// often — the box it types into — under it. Measured on an iPhone 12 mini:
// every /budgets amount cell rendered 34px, signup and login 42px.

it('puts a typed box on the same touch floor as a button', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, "):not([type='file']):not([type='hidden']),");
    expect($start)->not->toBeFalse('No coarse-pointer floor covers text inputs.');

    $rule = substr($css, (int) $start, 400);

    expect($rule)->toContain('textarea')
        ->and($rule)->toContain('min-height: 44px;');
});

it('keeps the floor to coarse pointers, so desktop density is untouched', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = (int) strpos($css, "):not([type='file']):not([type='hidden']),");
    $before = substr($css, 0, $start);

    expect(substr_count($before, '@media (pointer: coarse)'))->toBeGreaterThan(0);

    // The nearest block opener before the rule is the coarse-pointer one.
    $lastMedia = strrpos($before, '@media (');
    expect(substr($before, (int) $lastMedia, 30))->toContain('pointer: coarse');
});
