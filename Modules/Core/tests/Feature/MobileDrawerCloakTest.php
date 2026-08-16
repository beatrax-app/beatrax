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
 * hid it. The fix cloaks both surfaces with `x-cloak` AND backs the attribute
 * with a stylesheet rule — the app ships no global `[x-cloak]` rule, so the
 * attribute is inert on its own.
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

it('backs x-cloak with a mobile-scoped stylesheet rule so the drawer is hidden pre-boot', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // Without a rule the attribute does nothing: assert both drawer surfaces
    // are targeted while cloaked so the flash is actually suppressed.
    expect($css)->toContain('.drawer-scrim[x-cloak]')
        ->and($css)->toContain('.drawer-container[x-cloak]');
});
