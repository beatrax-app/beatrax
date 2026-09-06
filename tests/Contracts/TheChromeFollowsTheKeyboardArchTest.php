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

    expect($rule)->not->toBe('', 'Nothing holds the chrome against the visual viewport.');

    expect(str_contains($rule, 'translateY(var(--vv-offset-top'))->toBeTrue(
        'The rule exists but no longer moves by the visual viewport offset, so the top bar and '
        .'the status-bar scrim sit above the visible area whenever the soft keyboard is up.',
    );
});

// The class is what keeps the transform off at rest: a transform on .top-bar
// makes it the containing block for any fixed descendant.
it('sets the offset from visualViewport and only while it is displaced', function (): void {
    $app = (string) file_get_contents(base_path('resources/js/app.js'));

    // The denominator. An unreadable or renamed entry point makes every needle
    // below a question about the empty string, which reads as five missing
    // declarations rather than as a file nobody opened.
    expect(strlen($app))->toBeGreaterThan(500, 'resources/js/app.js read as all but empty — the path is wrong, not the code.');

    $missing = [];

    foreach ([
        'window.visualViewport' => 'nothing reads the visual viewport at all',
        "setProperty('--vv-offset-top'" => 'the offset is never published to CSS',
        "classList.toggle('kb-offset', offset > 0)" => 'the transform is not held off at rest, and a transform on .top-bar makes it the containing block for every fixed descendant',
        "viewport.addEventListener('resize', syncKeyboardOffset)" => 'the offset is not resynced when the keyboard opens or closes',
        "viewport.addEventListener('scroll', syncKeyboardOffset)" => 'the offset is not resynced when the visual viewport is panned',
    ] as $needle => $consequence) {
        if (! str_contains($app, $needle)) {
            $missing[] = $needle.' — '.$consequence;
        }
    }

    expect($missing)->toBe([], "The keyboard offset is no longer wired end to end:\n  ".implode("\n  ", $missing));
});

// The meta tag stays: it is the right declaration, it works where it is
// implemented, and the CSS above is what covers the engine that ignores it.
it('still asks the browser to resize the layout viewport', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect(str_contains($layout, 'interactive-widget=resizes-content'))->toBeTrue(
        'The layout stopped asking the browser to resize the layout viewport. The CSS above only '
        .'covers the engine that ignores this declaration; it is not a replacement for it.',
    );
});
