<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// 375px is an iPhone 12 mini and 411px a Galaxy S24, and both are narrower than
// the 24rem this panel asks for. The panel is also an 18px trigger's worth of
// reach away from being untappable, and the coarse-pointer floor cannot supply
// that reach here: min-width: 44px REPLACES min-width: auto, so a floor on a
// control sitting in a heading row squeezes the heading beside it instead.

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
});

it('never asks for more width than the narrower of the two phones has', function (): void {
    $panel = CssRule::blockFor($this->css, '.help-tip-panel {');

    expect($panel)->not->toBe('', 'No rule in app.css declares .help-tip-panel.');
    expect($panel)->toContain('width: min(24rem, calc(100vw - 2rem));');
});

// Without a paired :popover-open the author display:none wins over the UA rule
// that reveals an open popover, and the panel never appears at all.
it('hides the panel until it is opened, and reveals it when it is', function (): void {
    expect(CssRule::blockFor($this->css, '.help-tip-panel {'))->toContain('display: none;')
        ->and(CssRule::blockFor($this->css, '.help-tip-panel:popover-open'))->toContain('display: block;');
});

it('reaches 44px through the shared halo rather than through the floor', function (): void {
    expect(CssRule::selectorListFor($this->css, '.tap-chip::after,'))->toContain('.help-tip::after');

    $optOut = CssRule::selectorListFor($this->css, '.fx-disclosure-trigger,');
    expect($optOut)->toContain('.help-tip,');

    $optOutBlock = CssRule::blockFor($this->css, '.fx-disclosure-trigger,');
    expect($optOutBlock)->toContain('min-width: 0;')
        ->and($optOutBlock)->toContain('position: relative;');
});

it('keeps that reach on coarse pointers only, so desktop density is untouched', function (): void {
    expect(CssRule::atRuleEnclosing($this->css, '.help-tip::after'))->toContain('pointer: coarse');
});

// The same inode is mounted at both composer roots. A rule written to one and
// not the other ships a styled desktop and an unstyled phone.
it('reaches the mobile root through the file both roots share', function (): void {
    expect(fileinode(base_path('resources/css/app.css')))
        ->toBe(fileinode(base_path('mobile-app/resources/css/app.css')));
});
