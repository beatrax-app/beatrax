<?php

declare(strict_types=1);

// The navigation drawer and the command palette are both role="dialog"
// aria-modal="true", and nothing made the page behind them unreachable. Read
// off an iPhone 12 mini from the tree VoiceOver itself uses: with the drawer
// open, "August 2026", "This period totals, region" and the €3,202.14 under
// the scrim were all still announced. Controls left focusable outside the
// dialog — drawer 15, palette 97, which is the whole transaction ledger.

/** @return string the authenticated layout, which is where both overlays are mounted */
function drawerInertLayout(): string
{
    $path = base_path('resources/views/layouts/app.blade.php');

    expect(is_file($path))->toBeTrue($path.' is not readable from this Composer root, so nothing below was measured.');

    return (string) file_get_contents($path);
}

it('marks the page content inert while the drawer is open', function (): void {
    $layout = drawerInertLayout();

    $start = strpos($layout, '<main class="flex-1 min-w-0 overflow-auto"');
    expect($start)->not->toBeFalse('The authenticated layout no longer has that main element.');

    // 200 characters is the whole start tag with room to spare; reading the
    // file would pair this main with a binding on some later element.
    expect(str_contains(substr($layout, (int) $start, 200), 'x-bind:inert="$store.overlay.blocking'))->toBeTrue(
        'The page content is not inerted while an overlay is up, so everything under the scrim stays reachable to '.
        'a screen reader and to the tab order.'
    );
});

// inert is a BOOLEAN attribute: inert="false" is still inert. Measured in
// WebKit on the device — "", "true" and "false" each leave 0 focusable
// descendants, and only removing the attribute brings them back. So the
// expression has to resolve to null when the drawer is shut, never to false.
it('resolves to null rather than false when the drawer is shut', function (): void {
    $layout = drawerInertLayout();

    $start = strpos($layout, 'x-bind:inert=');
    expect($start)->not->toBeFalse('The authenticated layout binds inert nowhere at all.');

    expect(str_contains(substr($layout, (int) $start, 80), '|| null'))->toBeTrue(
        'The inert binding can resolve to false, and inert="false" is still inert — the whole page would stay '.
        'unreachable with no overlay on screen.'
    );
});

// The scrim is aria-hidden, so the hamburger is the only way a screen reader
// gets back out. Inerting the top bar as well would be a trap with no exit.
it('leaves the top bar reachable under the drawer, but not under the palette', function (): void {
    $layout = drawerInertLayout();

    $topBar = strpos($layout, '<x-core::mobile-top-bar');
    expect($topBar)->not->toBeFalse('The authenticated layout no longer mounts the mobile top bar.');

    // The tag itself, not the markup around it: the comments explaining both
    // bindings sit either side of it and name inert.
    $tag = substr($layout, (int) $topBar, (int) strpos($layout, '>', (int) $topBar) - (int) $topBar);

    expect(str_contains($tag, "x-bind:inert=\"\$store.overlay.has('palette') || null\""))->toBeTrue(
        'The top bar is inerted for the wrong set of overlays. Under the drawer it has to stay reachable — the '.
        'scrim is aria-hidden and the hamburger is the only way back out — and under the palette it must not be.'
    );
});

// Names rather than a counter: a double open() must not be able to leave the
// app inert with nothing on screen.
it('tracks which overlays are up by name', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/app.js'));

    $start = strpos($js, "Alpine.store('overlay'");
    expect($start)->not->toBeFalse('No overlay store.');

    // 420 characters covers the store body as written; a counter reinstated
    // below it would sit outside this window and is not what is being read.
    $store = substr($js, (int) $start, 420);

    $missing = array_values(array_filter(
        ['names: []', 'if (!this.names.includes(name))', 'get blocking()'],
        static fn (string $part): bool => ! str_contains($store, $part),
    ));

    expect($missing)->toBe([], implode("\n  ", [
        'The overlay store no longer tracks which overlays are up by name:',
        ...$missing,
        '',
        'A counter cannot survive a double open(): two opens and one close leave it',
        'at one, and the app stays inert with nothing on screen and no way out.',
    ]));
});

// Both overlays have to register, or the one that does not still leaks.
it('registers both the drawer and the palette with that store', function (): void {
    $app = (string) file_get_contents(base_path('resources/js/app.js'));
    $palette = (string) file_get_contents(base_path('resources/js/palette.js'));

    $missing = [];

    foreach (["store('overlay').add('drawer')", "store('overlay').remove('drawer')"] as $call) {
        if (! str_contains($app, $call)) {
            $missing[] = 'resources/js/app.js — '.$call;
        }
    }

    foreach (["store('overlay')?.add('palette')", "store('overlay')?.remove('palette')"] as $call) {
        if (! str_contains($palette, $call)) {
            $missing[] = 'resources/js/palette.js — '.$call;
        }
    }

    expect($missing)->toBe([], implode("\n  ", [
        'An overlay opens without registering with the store the layout reads:',
        ...$missing,
        '',
        'The store is what makes the page behind a dialog unreachable. An overlay',
        'that does not register leaves the whole ledger announced under its scrim,',
        'and the one that does register still passes this file without both halves.',
    ]));
});
