<?php

declare(strict_types=1);

// Measured on the Samsung at its largest display size and font size — a 320px
// viewport with 20.8px text. The phone search box is a flex item, and a flex
// item's default min-width is auto, so it kept the intrinsic width of its own
// default twenty characters: 304px inside a 256px wrap, right edge at 364 in a
// 320 viewport, with the page scrolling sideways to reach it.
it('lets the phone search box shrink to the row it is in', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.srch-input {');
    expect($start)->not->toBeFalse();

    $rule = substr($css, (int) $start, 240);

    expect($rule)->toContain('flex: 1')
        ->and($rule)->toContain('min-width: 0');
});
