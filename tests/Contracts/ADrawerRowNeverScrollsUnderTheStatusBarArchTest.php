<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// A safe-area inset spent as padding on a SCROLLER only protects the first
// screen of it. The nav drawer measured 1776px of rows in an 812px viewport --
// 2.19 screens -- so past the first, every row scrolled up under the iOS
// status bar: "Reconcile" was captured rendering through the clock. The inset
// has to shorten the scroll viewport, which means it belongs to the fixed
// panel around it.

it('spends the drawer inset on the panel, not on the list that scrolls inside it', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $scroller = CssRule::blockFor($css, '.drawer-container .side');
    expect($scroller)->not->toBe('', 'The drawer no longer sizes the sidebar inside it.')
        ->and($scroller)->toContain('height: 100%;')
        ->and($scroller)->not->toContain('padding-top: calc(var(--space-4) + var(--safe-top));');

    // The panel rule that took it over, read from inside the same phone-width
    // block: `.drawer-container` is also the reduced-motion rule and the
    // desktop sidebar, so the first one in the file is neither of these.
    $at = strpos($css, '.drawer-container .side');
    $panel = substr($css, (int) $at, 900);

    expect($panel)->toContain('padding-top: var(--safe-top);')
        ->and($panel)->toContain('background: var(--color-bg-subtle);');
});

it('keeps that inset off the desktop sidebar the same class becomes', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(CssRule::atRuleEnclosing($css, '.drawer-container .side'))->toContain('max-width: 1023px');
});
