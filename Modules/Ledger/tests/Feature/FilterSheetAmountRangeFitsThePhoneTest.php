<?php

declare(strict_types=1);

// Measured in the phone filter sheet on an iPhone 12 mini: `flex: 1` alone
// cannot shrink a text input below its intrinsic size, so Min and Max held
// 211px each and the Max box ended 88pt past the right edge of the screen.

it('lets the amount range shrink to the sheet it sits in', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    foreach (['.srch-amount-input', '.srch-date-input'] as $selector) {
        $start = strpos($css, $selector.' {');
        expect($start)->not->toBeFalse("No rule for {$selector}.");

        $rule = substr($css, (int) $start, 400);
        $rule = substr($rule, 0, (int) strpos($rule, '}'));

        expect($rule)->toContain('min-width: 0;');
    }
});
