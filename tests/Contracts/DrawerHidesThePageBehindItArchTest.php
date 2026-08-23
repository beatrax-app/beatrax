<?php

declare(strict_types=1);

// The navigation drawer is role="dialog" aria-modal="true", and nothing made
// the page behind it unreachable. Read off an iPhone 12 mini from the tree
// VoiceOver itself uses: with the drawer open, "August 2026", "This period
// totals, region" and the €3,202.14 under the scrim were all still announced,
// and 15 controls outside the dialog were still focusable.

it('marks the page content inert while the drawer is open', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    $start = strpos($layout, '<main class="flex-1 min-w-0 overflow-auto"');
    expect($start)->not->toBeFalse('The authenticated layout no longer has that main element.');

    expect(substr($layout, (int) $start, 200))->toContain('x-bind:inert="$store.mobileNav.drawerOpen');
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
it('leaves the top bar reachable so the drawer can be closed', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    $topBar = strpos($layout, '<x-core::mobile-top-bar');
    expect($topBar)->not->toBeFalse();

    // The tag itself, not the markup that follows it: the comment explaining
    // the <main> binding sits directly below and names inert.
    $tag = substr($layout, (int) $topBar, (int) strpos($layout, '>', (int) $topBar) - (int) $topBar);

    expect($tag)->not->toContain('inert');
});
