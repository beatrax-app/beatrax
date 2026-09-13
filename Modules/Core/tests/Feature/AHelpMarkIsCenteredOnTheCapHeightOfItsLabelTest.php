<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// Measured in a headless engine against the built stylesheet at 375px and
// 411px: the mark's centre sat 5.98px below the cap-height centre of a 28px
// page heading, low enough that its lower edge read as hanging in the word's
// descenders. A circle is a geometric mark rather than a letter, so it belongs
// on the cap height of the text beside it and not on that text's baseline.

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
});

// `middle` reaches the x-height and stops there; the transform carries the mark
// the rest of the way. Both are read from the mark's own inherited font, which
// is what lets one rule serve a 28px heading and a 12px column header.
it('lifts the mark from the x-height to the cap height of the text it sits in', function (): void {
    $mark = CssRule::blockFor($this->css, '.help-tip {');

    expect($mark)->not->toBe('', 'No rule in app.css declares .help-tip.')
        ->and($mark)->toContain('vertical-align: middle;')
        ->and($mark)->toContain('transform: translateY(calc((1ex - 1cap) / 2));');
});

// The h1 that carries a mark is inline, so it cannot hold a block-level line
// breaking decision itself, and the block around it has to take the one every
// other heading gets.
it('leaves the block a heading shares with its mark breaking lines like a heading', function (): void {
    expect(CssRule::selectorListFor($this->css, '.heading-with-tip'))->toContain('h1,')
        ->and(CssRule::blockFor($this->css, '.heading-with-tip'))->toContain('text-wrap: balance;')
        ->and(CssRule::atRuleEnclosing($this->css, '.heading-with-tip'))->toContain('pointer: coarse');
});

// The top layer is a paint decision, not an inheritance one: the panel still
// inherits from the block it is written inside, and balanced lines and
// automatic hyphens are a decision about a two-word title.
it('keeps that heading treatment out of the panel written inside it', function (): void {
    $panel = CssRule::blockFor($this->css, '.help-tip-panel {');

    expect($panel)->toContain('text-wrap: wrap;')
        ->and($panel)->toContain('hyphens: manual;');
});
