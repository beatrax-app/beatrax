<?php

declare(strict_types=1);

use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Native\AppShellScreen;
use Native\Mobile\Testing\Native;

/*
 * Stage B: native chrome around the existing Livewire body.
 *
 * This is the seam that decides whether the app can feel native without a
 * second UI. The chrome (top bar, bottom nav) is real SwiftUI / Jetpack
 * Compose; the body is the same `/`, `/transactions`, `/calendar` and
 * `/settings` the desktop renders, embedded through the app's own PHP
 * runtime. Nothing here re-implements a screen.
 *
 * `Native::test()` renders through `createElement`, which is a DIFFERENT
 * code path from the device's streaming collector — a prop can pass here
 * and be missing on a phone. So these assert structure and state, the parts
 * that are shared, and leave paint to the on-device pass.
 */

beforeEach(function (): void {
    // The EDGE runtime lives only in mobile-app/vendor (nativephp/desktop
    // conflicts with nativephp/mobile, hence the sibling roots), so this
    // file is a no-op under the repo-root toolchain. Skipping beats a
    // `repo-root-only`-style group here: the reason is a missing package,
    // and asking the class directly cannot drift out of date.
    if (! class_exists(Native::class)) {
        test()->markTestSkipped('nativephp/mobile is installed only under mobile-app/.');
    }
});

/**
 * Every bottom_nav_item in a rendered tree, in render order.
 *
 * @param  array<string, mixed>  $node
 * @return list<array{props: array{label: string, active: bool}}>
 */
function navItems(array $node): array
{
    $found = [];

    if (($node['type'] ?? null) === 'bottom_nav_item') {
        $found[] = $node;
    }

    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $found = [...$found, ...navItems($child)];
        }
    }

    return $found;
}

it('wraps the web body in native chrome rather than replacing it', function (): void {
    // Inline <top-bar> / <bottom-nav> do NOT survive as tree nodes: the
    // component hoists them onto the native shell, so the root becomes
    // native_root_tabs carrying nav_title, with the items promoted beside
    // the content. Asserting on `top_bar` here would fail against a screen
    // that is in fact correct.
    Native::test(AppShellScreen::class)
        ->assertElement('native_root_tabs')
        ->assertElement('bottom_nav_item')
        // The body stays a webview: if this ever becomes a native tree, the
        // shared-resources seam has been broken and the whole cost model of
        // this approach changes.
        ->assertElement('webview');
});

it('drops an untitled top bar, which is why the title is not decoration', function (): void {
    // Recorded because it cost real time: a bare <top-bar /> contributes
    // nothing to hoist, so the bar never reaches the shell and nav_title is
    // absent — with no error anywhere. The title is load-bearing.
    Native::test(AppShellScreen::class)
        ->assertElement(
            'native_root_tabs',
            fn (array $el): bool => ($el['props']['nav_title'] ?? null) === 'Dashboard',
        );
});

it('serves the body through the app runtime, not as a foreign page', function (): void {
    // `php` is what gives the embedded view the shared session, the asset
    // pipeline and the window.Native bridge. Without it the webview is a
    // sandboxed foreign document and every authenticated route 302s to
    // login inside the frame.
    Native::test(AppShellScreen::class)
        ->assertElement('webview', fn (array $el): bool => ($el['props']['php'] ?? false) === true);
});

it('starts on the dashboard', function (): void {
    Native::test(AppShellScreen::class)
        ->assertSet('path', '/')
        ->assertSet('active', 'dashboard')
        ->assertNavTitle('Dashboard');
});

it('moves the web body when a bottom-nav destination is chosen', function (): void {
    Native::test(AppShellScreen::class)
        ->call('open', 'transactions')
        ->assertSet('path', '/transactions')
        ->assertSet('active', 'transactions')
        ->assertNavTitle('Transactions');
});

it('ignores an unknown destination instead of blanking the body', function (): void {
    // The key arrives from a rendered nav item, but a stale tree or a
    // client-side call could send anything. Falling through to an empty
    // $path would load the start URL and look like a random navigation.
    Native::test(AppShellScreen::class)
        ->call('open', 'transactions')
        ->call('open', 'not-a-destination')
        ->assertSet('path', '/transactions')
        ->assertSet('active', 'transactions');
});

it('re-syncs the chrome when the page navigates itself', function (): void {
    // A link tapped inside the webview moves the body without going through
    // open(). Without the @navigated hook the bottom bar keeps pointing at
    // whatever was last tapped, which is worse than no highlight at all.
    Native::test(AppShellScreen::class)
        ->call('onNavigated', 'https://beatrax.test/calendar')
        ->assertSet('active', 'calendar');
});

it('keeps a nested path on its parent destination', function (): void {
    Native::test(AppShellScreen::class)
        ->call('onNavigated', 'https://beatrax.test/transactions/42')
        ->assertSet('active', 'transactions');
});

it('does not let the root destination claim every path', function (): void {
    // `/` prefixes everything, so a naive str_starts_with match would light
    // Home on every screen in the app.
    Native::test(AppShellScreen::class)
        ->call('onNavigated', 'https://beatrax.test/settings')
        ->assertSet('active', 'settings');
});

it('leaves the chrome alone for a path no destination owns', function (): void {
    Native::test(AppShellScreen::class)
        ->call('open', 'calendar')
        ->call('onNavigated', 'https://beatrax.test/uncategorized')
        // Better a stale highlight than a wrong one: the user is somewhere
        // the bar cannot represent, and blanking it mid-flow reads as a bug.
        ->assertSet('active', 'calendar');
});

it('renders one nav item per destination, with exactly one active', function (): void {
    // Counted off the tree rather than through assertElement(), which is
    // satisfied by the first match and would pass on a single item.
    $items = navItems(Native::test(AppShellScreen::class)->tree());

    expect($items)->toHaveCount(count(AppShellScreen::DESTINATIONS));

    $labels = array_map(static fn (array $i): string => $i['props']['label'], $items);
    expect($labels)->toBe(array_values(array_map(
        static fn (array $d): string => $d['label'],
        AppShellScreen::destinations(),
    )));

    $active = array_filter($items, static fn (array $i): bool => $i['props']['active'] === true);
    expect($active)->toHaveCount(1);
});

it('moves the active marker with the destination', function (): void {
    $items = navItems(Native::test(AppShellScreen::class)->call('open', 'calendar')->tree());

    $activeLabels = array_values(array_map(
        static fn (array $i): string => $i['props']['label'],
        array_filter($items, static fn (array $i): bool => $i['props']['active'] === true),
    ));

    expect($activeLabels)->toBe([AppShellScreen::destinations()['calendar']['label']]);
});

it('renders the bar in the active locale', function (): void {
    // The whole reason the labels are sidebar keys rather than literals: a
    // Dutch device must not get an English bottom bar bolted onto a Dutch
    // page. Asserts against the lang file so it cannot drift from it.
    app()->setLocale('nl');

    $items = navItems(Native::test(AppShellScreen::class)->tree());
    $labels = array_map(static fn (array $i): string => $i['props']['label'], $items);

    expect($labels)->toContain(Lang::get('core::sidebar.nav.transactions'));
    expect($labels)->not->toContain('Transactions');
});
