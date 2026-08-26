<?php

declare(strict_types=1);

// The navigation drawer and the command palette are both role="dialog"
// aria-modal="true", and nothing made the page behind them unreachable. Read
// off an iPhone 12 mini from the tree VoiceOver itself uses: with the drawer
// open, "August 2026", "This period totals, region" and the €3,202.14 under
// the scrim were all still announced. Controls left focusable outside the
// dialog — drawer 15, palette 97, which is the whole transaction ledger.

it('marks the page content inert while the drawer is open', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    $start = strpos($layout, '<main class="flex-1 min-w-0 overflow-auto"');
    expect($start)->not->toBeFalse('The authenticated layout no longer has that main element.');

    expect(substr($layout, (int) $start, 200))->toContain('x-bind:inert="$store.overlay.blocking');
});

// inert is a BOOLEAN attribute: inert="false" is still inert. Measured in
// WebKit on the device — "", "true" and "false" each leave 0 focusable
// descendants, and only removing the attribute brings them back. So the
// expression has to resolve to null when the drawer is shut, never to false.
it('resolves to null rather than false when the drawer is shut', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    $start = (int) strpos($layout, 'x-bind:inert=');

    expect(substr($layout, $start, 80))->toContain('|| null');
});

// The scrim is aria-hidden, so the hamburger is the only way a screen reader
// gets back out. Inerting the top bar as well would be a trap with no exit.
it('leaves the top bar reachable under the drawer, but not under the palette', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    $topBar = strpos($layout, '<x-core::mobile-top-bar');
    expect($topBar)->not->toBeFalse();

    // The tag itself, not the markup around it: the comments explaining both
    // bindings sit either side of it and name inert.
    $tag = substr($layout, (int) $topBar, (int) strpos($layout, '>', (int) $topBar) - (int) $topBar);

    expect($tag)->toContain("x-bind:inert=\"\$store.overlay.has('palette') || null\"");
});

// Names rather than a counter: a double open() must not be able to leave the
// app inert with nothing on screen.
it('tracks which overlays are up by name', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/app.js'));

    $start = strpos($js, "Alpine.store('overlay'");
    expect($start)->not->toBeFalse('No overlay store.');

    $store = substr($js, (int) $start, 420);

    expect($store)->toContain('names: []')
        ->and($store)->toContain('if (!this.names.includes(name))')
        ->and($store)->toContain('get blocking()');
});

// Both overlays have to register, or the one that does not still leaks.
it('registers both the drawer and the palette with that store', function (): void {
    expect((string) file_get_contents(base_path('resources/js/app.js')))
        ->toContain("store('overlay').add('drawer')")
        ->and((string) file_get_contents(base_path('resources/js/app.js')))
        ->toContain("store('overlay').remove('drawer')");

    expect((string) file_get_contents(base_path('resources/js/palette.js')))
        ->toContain("store('overlay')?.add('palette')")
        ->and((string) file_get_contents(base_path('resources/js/palette.js')))
        ->toContain("store('overlay')?.remove('palette')");
});
