<?php

declare(strict_types=1);

// A halo that extends a control's reach cannot be a fixed square: on anything
// wider than 44px it lands INSIDE the control and adds nothing but a strip at
// the centre. Measured on an iPhone 12 mini: the 324x36 welcome links answered
// a tap 3px above them at 1 of 11 x positions across their own width.

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));

    $anchor = strpos($this->css, '.tap-chip::after,');
    expect($anchor)->not->toBeFalse('No shared touch halo declares .tap-chip.');

    $this->anchor = (int) $anchor;
    $open = (int) strpos($this->css, '{', $this->anchor);
    $this->rule = substr($this->css, $open, (int) strpos($this->css, '}', $open) - $open);
});

it('sizes the shared touch halo from the control, never below it', function (): void {
    expect($this->rule)->toContain('width: max(100%, 44px);')
        ->and($this->rule)->toContain('height: max(100%, 44px);')
        ->and($this->rule)->not->toContain('width: 44px;')
        ->and($this->rule)->not->toContain('height: 44px;');
});

it('centres that halo on the control it belongs to', function (): void {
    expect($this->rule)->toContain('position: absolute;')
        ->and($this->rule)->toContain('transform: translate(-50%, -50%);');
});

it('keeps the halo to coarse pointers, so desktop density is untouched', function (): void {
    $before = substr($this->css, 0, $this->anchor);

    expect(substr($before, (int) strrpos($before, '@media ('), 30))->toContain('pointer: coarse');
});
