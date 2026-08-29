<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// The soft keyboard offsets the visual viewport and leaves the layout viewport
// alone. `interactive-widget=resizes-content` is supposed to stop that and is
// inert in the Android WebView, so the sticky top bar and the scrim that keeps
// page content out of the status bar both sit above the visible area. Measured
// on a Galaxy S24 Ultra: visualViewport.offsetTop 302.9 with innerHeight
// unchanged, .top-bar at layout y 0, and a card border drawn beside the clock.

it('holds the top bar and the status-bar scrim against the visual viewport', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $rule = CssRule::blockFor($css, ".kb-offset .top-bar,\n    .kb-offset .safe-screen::before {");

    expect($rule)->not->toBe('', 'Nothing holds the chrome against the visual viewport.')
        ->and($rule)->toContain('translateY(var(--vv-offset-top');
});

// The class is what keeps the transform off at rest: a transform on .top-bar
// makes it the containing block for any fixed descendant.
it('sets the offset from visualViewport and only while it is displaced', function (): void {
    $app = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($app)->toContain('window.visualViewport')
        ->and($app)->toContain("setProperty('--vv-offset-top'")
        ->and($app)->toContain("classList.toggle('kb-offset', offset > 0)")
        ->and($app)->toContain("viewport.addEventListener('resize', syncKeyboardOffset)")
        ->and($app)->toContain("viewport.addEventListener('scroll', syncKeyboardOffset)");
});

// The meta tag stays: it is the right declaration, it works where it is
// implemented, and the CSS above is what covers the engine that ignores it.
it('still asks the browser to resize the layout viewport', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect($layout)->toContain('interactive-widget=resizes-content');
});
