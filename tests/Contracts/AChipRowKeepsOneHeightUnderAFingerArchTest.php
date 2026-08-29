<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// A row of chips declares one height for all of them. `min-height: 44px` from
// the touch floor is a CONSTRAINT, applied after the used value of `height`, so
// no utility and no specificity can beat it: whichever chip the floor reaches
// stands at 44 while its neighbours stay at 20. On /transactions that was the
// cleared badge, a capsule whose 8px side padding was drawn for a 23px pill and
// which at 44px put its own label inside its end cap.
//
// The floor is released for these and the reach moves to the ::after halo, so
// the finger still gets 44px and the row keeps one height.

const CHIP_ROW_MEMBERS = [
    '.split-badge',
    '.tax-badge',
    '.tax-badge--untagged',
    '.cleared-badge-toggle',
];

// Unique to the release list, so it cannot match the older width-keyed halo
// that names .chip and .tap-link earlier in the file.
const FLOOR_RELEASE_ANCHOR = '.cleared-badge-toggle,';

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
    $this->released = CssRule::selectorListFor($this->css, FLOOR_RELEASE_ANCHOR);
});

it('releases the chips only where a finger is the pointer', function (): void {
    expect(CssRule::blockFor($this->css, FLOOR_RELEASE_ANCHOR))->toContain('min-height: 0;')
        ->and(CssRule::atRuleEnclosing($this->css, FLOOR_RELEASE_ANCHOR))->toContain('pointer: coarse');
});

it('releases every chip on the transactions row from the touch floor', function (string $chip): void {
    expect($this->released)->toContain($chip.',');
})->with(CHIP_ROW_MEMBERS);

// Releasing a chip without giving it a halo shrinks the picture AND the target.
it('gives every released chip the halo that replaces the floor', function (string $chip): void {
    expect(str_contains($this->css, $chip.'::after,'))
        ->toBeTrue($chip.' is released from the touch floor with no halo to replace it.');
})->with(CHIP_ROW_MEMBERS);
