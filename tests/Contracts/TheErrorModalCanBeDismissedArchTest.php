<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Helpers\CssRule;

/** @return string the padding the error dialog declares, or '' when it declares none */
function errorModalPadding(string $css): string
{
    $declared = PatternScan::first('/padding:\s*([^;}]+)/', CssRule::blockFor($css, 'dialog#livewire-error'));

    return $declared === [] ? '' : trim($declared[1]);
}

// Read as a value rather than as the substring `padding: 0`, which a perfectly
// good `padding: 0.5rem` also contains — and which would have failed this guard
// for a dialog that had exactly the surface it is about.
function errorModalPaddingIsNoSurface(string $padding): bool
{
    if ($padding === '') {
        return true;
    }

    foreach (PatternScan::split('/\s+/', $padding) as $side) {
        if ($side !== '' && (float) $side !== 0.0) {
            return false;
        }
    }

    return true;
}

// Livewire builds its 500 modal as a <dialog> containing an <iframe> sized
// 100%/100% at padding 0, and puts the close listener on the dialog. A click
// does not cross an iframe boundary, so with no padding the only thing that
// dismisses it is the backdrop — undiscoverable, and on iOS under the system
// edge-swipe areas. This pins the padding that gives the dialog a tappable
// surface of its own.
it('leaves the error modal a surface of its own to be dismissed by', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(strlen($css))->toBeGreaterThan(1000, 'resources/css/app.css read as all but empty — the path is wrong, not the stylesheet.');

    $padding = errorModalPadding($css);

    expect($padding)->not->toBe('', 'no rule gives Livewire\'s error modal a padding of its own.');

    expect(errorModalPaddingIsNoSurface($padding))->toBeFalse(sprintf(
        'The error dialog declares `padding: %s`, which leaves it no surface outside the iframe. '
        .'A click inside the iframe never reaches the dialog\'s close listener, so the only way '
        .'out is the backdrop — undiscoverable, and under iOS\'s own edge-swipe areas.',
        $padding,
    ));
});

it('reads a zero padding in every spelling, and a real one as real', function (): void {
    $zero = ['0', '0px', '0rem', '0 0', '0px 0px 0px 0px'];
    $real = ['12px', '0.5rem', '0 12px', '1px'];

    $wrong = [];

    foreach ($zero as $padding) {
        if (! errorModalPaddingIsNoSurface($padding)) {
            $wrong[] = 'read `'.$padding.'` as a surface';
        }
    }

    foreach ($real as $padding) {
        if (errorModalPaddingIsNoSurface($padding)) {
            $wrong[] = 'read `'.$padding.'` as no surface';
        }
    }

    expect($wrong)->toBe([], "The padding reader answers the wrong way round:\n  ".implode("\n  ", $wrong));
});

it('finds the dialog rule it is named for, so a silent scan cannot pass this file', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(CssRule::blockFor($css, 'dialog#livewire-error'))->not->toBe(
        '',
        'no rule targets Livewire\'s error modal, so the assertion above is about the empty string.',
    );
});
