<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// A 44px floor that names only buttons leaves the control a finger reaches most
// often — the box it types into — under it. Measured on an iPhone 12 mini:
// every /budgets amount cell rendered 34px, signup and login 42px.
const TYPED_BOX_FLOOR_SELECTOR = "):not([type='file']):not([type='hidden']),";

it('puts a typed box on the same touch floor as a button', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(strlen($css))->toBeGreaterThan(1000, 'resources/css/app.css read as all but empty — the path is wrong, not the stylesheet.');

    $selectors = CssRule::selectorListFor($css, TYPED_BOX_FLOOR_SELECTOR);

    expect($selectors)->not->toBe('', 'No coarse-pointer floor covers text inputs.');

    // Read as the rule's own selector list and its own declaration block rather
    // than as a fixed window of characters after the selector: too short and a
    // present declaration reads as missing, too long and a neighbour's answers
    // in its place.
    expect(str_contains($selectors, 'textarea'))->toBeTrue(
        'The typed-box floor no longer names textarea, so a multi-line box is back under 44px.',
    );

    expect(str_contains(CssRule::blockFor($css, TYPED_BOX_FLOOR_SELECTOR), 'min-height: 44px;'))->toBeTrue(
        'The typed-box rule exists but sets no 44px floor, which is the whole of what it is for.',
    );
});

it('keeps the floor to coarse pointers, so desktop density is untouched', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // Asked of the block structure rather than of the nearest `@media (` above
    // the rule: searching backwards lands on the last at-rule that CLOSED
    // before the selector just as readily as on the one holding it.
    expect(CssRule::atRuleEnclosing($css, TYPED_BOX_FLOOR_SELECTOR))->toBe(
        '@media (pointer: coarse)',
        'The typed-box floor is no longer inside the coarse-pointer block, so a 44px minimum is '
        .'being applied to a mouse-driven desktop the design specifies at its own density.',
    );
});
