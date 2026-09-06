<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// A halo that extends a control's reach cannot be a fixed square: on anything
// wider than 44px it lands INSIDE the control and adds nothing but a strip at
// the centre. Measured on an iPhone 12 mini: the 324x36 welcome links answered
// a tap 3px above them at 1 of 11 x positions across their own width.

// The trailing comma is load-bearing: `.tap-chip::after` is one selector part
// way down a shared list, and without it the anchor would also match the
// `.tap-chip` rule above that only declares `position: relative`.
const HALO_SELECTOR = '.tap-chip::after,';

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
    $this->rule = CssRule::blockFor($this->css, HALO_SELECTOR);

    expect($this->rule)->not->toBe('', 'No shared touch halo declares .tap-chip.');
});

it('sizes the shared touch halo from the control, never below it', function (): void {
    expect(str_contains($this->rule, 'width: max(100%, 44px);'))->toBeTrue(
        'The halo no longer takes its width from the control it belongs to. A fixed square is SMALLER '
        .'than a wide control, so it shrinks the reach to a strip at the centre instead of extending it.'
    );

    expect(str_contains($this->rule, 'height: max(100%, 44px);'))->toBeTrue(
        'The halo no longer takes its height from the control it belongs to, so a control taller than '
        .'44px answers a tap on part of itself only.'
    );

    expect(str_contains($this->rule, 'width: 44px;'))->toBeFalse(
        'The fixed square is back. It is the exact shape measured as broken on an iPhone 12 mini: the '
        .'324x36 welcome links answered a tap 3px above them at 1 of 11 x positions.'
    );

    expect(str_contains($this->rule, 'height: 44px;'))->toBeFalse(
        'The fixed square is back in the height axis, so a control taller than 44px loses the ends of '
        .'its own reach.'
    );
});

it('centres that halo on the control it belongs to', function (): void {
    expect(str_contains($this->rule, 'position: absolute;'))->toBeTrue(
        'A halo that is not taken out of flow moves the control it was added to rather than covering it.'
    );

    expect(str_contains($this->rule, 'transform: translate(-50%, -50%);'))->toBeTrue(
        'The halo is placed at the control\'s centre point and has to be pulled back by half its own '
        .'size; without this it hangs off the bottom-right and reaches past its neighbour instead.'
    );
});

it('keeps the halo to coarse pointers, so desktop density is untouched', function (): void {
    expect(CssRule::atRuleEnclosing($this->css, HALO_SELECTOR))->toContain('pointer: coarse');
});

// The three rules above all read one string, and every one of them passes when
// that string comes back empty for a reason other than the one the beforeEach
// names. This drives the same reader over a stylesheet holding both traps: a
// selector that is a prefix of the anchor, and a nested at-rule inside the
// block that a brace-counting reader would end early on.
it('reads the whole block belonging to the selector and not a neighbour\'s', function (): void {
    $css = <<<'CSS'
        .tap-chip { position: relative; }
        @media (pointer: coarse) {
            .chip::after,
            .tap-chip::after,
            .help-tip::after {
                content: '';
                width: max(100%, 44px);
                @supports (color: oklch(0 0 0)) { outline-color: oklch(0 0 0); }
            }
            .srch-chip-close::after { width: 24px; }
        }
        CSS;

    $block = CssRule::blockFor($css, HALO_SELECTOR);

    expect(str_contains($block, 'width: max(100%, 44px);'))->toBeTrue('the block belonging to the anchor was not the one returned');
    expect(str_contains($block, 'outline-color'))->toBeTrue('the nested at-rule ended the block early, so the declarations after it were never read');
    expect(str_contains($block, 'width: 24px;'))->toBeFalse('the reader ran on into the next rule, so a neighbour\'s declarations answer in this one\'s place');
    expect(str_contains($block, 'position: relative;'))->toBeFalse('the anchor matched the .tap-chip rule above rather than the ::after one it names');
    expect(CssRule::atRuleEnclosing($css, HALO_SELECTOR))->toContain('pointer: coarse');
});
