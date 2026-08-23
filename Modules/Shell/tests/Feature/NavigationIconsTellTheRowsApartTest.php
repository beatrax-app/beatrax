<?php

declare(strict_types=1);

use Modules\Shell\Public\Navigation\AppNavigation;

// Read off the drawer on an iPhone 12 mini: Prognoses and Abonnementen both
// wore ↗. A glyph column exists to tell the rows apart, and two rows carrying
// the same mark is the column doing the opposite of its job.

it('gives every navigation destination its own glyph', function (): void {
    $icons = [];
    $duplicates = [];

    foreach (AppNavigation::destinations() as $destination) {
        $icon = $destination->icon;
        if (isset($icons[$icon])) {
            $duplicates[] = $icon.' → '.$icons[$icon].' and '.$destination->id->name;
        }
        $icons[$icon] = $destination->id->name;
    }

    expect($duplicates)->toBe([], 'two destinations share a glyph: '.implode(', ', $duplicates));
});
