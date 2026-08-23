<?php

declare(strict_types=1);

// Measured on an iPhone 12 mini: the label, the path and the copy button shared
// one 275px row, which left the container path 108px and broke it every twelve
// characters into fourteen lines of a path nobody could read back.

it('gives the path the whole row once the phone cannot hold three across', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.help-locations .copy-path-btn:hover');
    expect($start)->not->toBeFalse();

    $tail = substr($css, (int) $start, 700);

    expect($tail)->toContain('@media (max-width: 640px)')
        ->and($tail)->toContain('.help-locations .path-row')
        ->and($tail)->toContain('flex-wrap: wrap;')
        ->and($tail)->toContain('flex-basis: 100%;');
});
