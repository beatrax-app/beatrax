<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// Dropping a select's native appearance takes its arrow with it, so the touch
// rule paints one and reserves a column for it in padding-right. `select` is an
// element selector, and the `px-3` almost every call site carries outranks it:
// measured at 384px, ten of ten visible selects reserved 12px against a 36px
// column and drew the arrow over the last of the selected option's own text.
//
// The reservation is therefore declared twice — once for every select, once
// again one point higher for the ones carrying a class. Not [class] alone: 12
// of the 30 selects in the tree have no class, x-core::form-field's among them,
// and they would lose the floor, the appearance reset and the chevron itself.

const CHEVRON_RESERVATION = 'padding-right: calc(var(--space-3) * 2 + 12px);';

// Anchored on the line break, so it cannot match .locale-switcher-select --
// which is a class, ends in the same eight characters, and sets padding with a
// shorthand that this rule's own repeat is one point above.
const BARE_SELECT_RULE = "\n    select {";

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
});

it('reserves the chevron column for every select a finger can reach', function (): void {
    expect(CssRule::selectorListFor($this->css, BARE_SELECT_RULE))->toContain('select')
        ->and(CssRule::blockFor($this->css, BARE_SELECT_RULE))->toContain(CHEVRON_RESERVATION)
        ->and(CssRule::blockFor($this->css, BARE_SELECT_RULE))->toContain('appearance: none;')
        ->and(CssRule::atRuleEnclosing($this->css, BARE_SELECT_RULE))->toContain('pointer: coarse');
});

it('repeats it above the utility padding the call sites carry', function (): void {
    expect(CssRule::blockFor($this->css, 'select[class] {'))->toContain(CHEVRON_RESERVATION)
        ->and(CssRule::atRuleEnclosing($this->css, 'select[class] {'))->toContain('pointer: coarse');
});

// The higher-specificity copy carries the padding and nothing else, so a select
// with no class keeps everything the base rule gives it.
it('leaves the classless selects everything but that one repeat', function (): void {
    $repeat = CssRule::blockFor($this->css, 'select[class] {');

    expect($repeat)->not->toContain('appearance')
        ->and($repeat)->not->toContain('min-height')
        ->and($repeat)->not->toContain('background-image');
});
