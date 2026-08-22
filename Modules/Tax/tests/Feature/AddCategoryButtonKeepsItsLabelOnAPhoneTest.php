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
