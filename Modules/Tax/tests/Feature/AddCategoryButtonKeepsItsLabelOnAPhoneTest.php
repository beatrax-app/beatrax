<?php

declare(strict_types=1);

// Measured on a 375pt iPhone: the input took `flex-1` and the button beside it
// had nothing holding its width, so it shrank onto the 44px touch floor and
// "Add category" wrapped and ran off the right edge of the card.

it('holds the add-category button at its own width beside the name box', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php')
    );

    $start = strpos($blade, 'data-testid="add-category-form"');
    expect($start)->not->toBeFalse();

    $form = substr($blade, (int) $start, 1400);

    expect($form)->toContain('shrink-0')
        ->and($form)->toContain('whitespace-nowrap');
});

// And measured again on the Samsung at the phone's own maximum font size: a
// flex item's default min-width is auto, so `flex-1` could not shrink the box
// below the intrinsic width of its default 20 characters. At font scale 1.3
// that came to 311px inside a 254px row — 57px past the card, with the settings
// page scrolling sideways to reach it.
it('lets the name box shrink to the row it is in', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php')
    );

    $start = strpos($blade, 'data-testid="add-category-form"');
    expect($start)->not->toBeFalse();

    $form = substr($blade, (int) $start, 1400);
    $input = substr($form, (int) strpos($form, '<input'), (int) strpos($form, '<button') - (int) strpos($form, '<input'));

    // basis-full rather than a shrunken flex-1: with the button on the same
    // line the box came out 137px at the default font size, narrower than it
    // is today. A whole line for the box and the button under it is what both
    // font sizes already do.
    expect($input)->toContain('min-w-0')
        ->and($input)->toContain('basis-full')
        ->and($input)->toContain('sm:basis-auto');
});
