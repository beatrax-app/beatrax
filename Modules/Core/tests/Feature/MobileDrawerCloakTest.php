<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * Regression: the mobile navigation drawer flashed on screen for a frame
 * whenever the authenticated layout was reached by a FULL page load — most
 * visibly the redirect to `/` right after unlocking at the PIN screen. The
 * drawer panel and its scrim are driven by `x-show="$store.mobileNav.drawerOpen"`,
 * which only applies `display: none` once Alpine has booted; the server HTML
 * paints first, so the whole nav panel showed over the dashboard until Alpine
 * hid it. The fix cloaks both surfaces with `x-cloak` and owns the backing
 * rule rather than leaving it to the copy `@livewireStyles` injects, whose
 * position after this app's stylesheet is what made the desktop exemption
 * below necessary.
 *
 * Uses /help/data-locations (not /) for the same reason PwaLayoutTest does:
 * it is a plain Route::view() that always renders the authenticated layout
 * (resources/views/layouts/app.blade.php) with no redirect conditions.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'drawer-cloak-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

// Grabs the full opening tag of the first <div> carrying the given class,
// regardless of attribute order — the tag has no '>' until it closes, so a
// negated-'>' run captures every attribute on it including newlines.
function drawerOpeningTag(string $html, string $class): string
{
    $pattern = '/<div\b[^>]*class="'.preg_quote($class, '/').'"[^>]*>/';

    return preg_match($pattern, $html, $matches) === 1 ? $matches[0] : '';
}

it('cloaks the drawer panel and scrim in the rendered authenticated layout', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $response->assertOk();
    $html = (string) $response->getContent();

    $scrim = drawerOpeningTag($html, 'drawer-scrim');
    $panel = drawerOpeningTag($html, 'drawer-container');

    expect($scrim)->not->toBe('')
        ->and($panel)->not->toBe('');

    expect($scrim)->toContain('x-cloak')
        ->and($panel)->toContain('x-cloak');
});

it('backs x-cloak with its own global rule rather than the framework-injected one', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain("[x-cloak] {\n    display: none !important;\n}");
});

it('exempts the desktop sidebar from the global cloak', function (): void {
    // At >=1024px the drawer container IS the static sidebar, and the rule
    // that keeps it laid out is a single class — the same weight as [x-cloak],
    // whose injected copy comes later in the cascade and therefore wins. The
    // exemption has to out-specify it or the desktop nav blanks until Alpine
    // boots.
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $desktopBlock = strstr($css, '@media (min-width: 1024px)');

    expect($desktopBlock)->toContain(".drawer-container[x-cloak] {\n        display: block !important;\n    }");
});
