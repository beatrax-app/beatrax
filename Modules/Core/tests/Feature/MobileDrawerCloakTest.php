<?php

declare(strict_types=1);

use Modules\Core\Models\User;

// The drawer panel and scrim are driven by `x-show`, which only applies
// `display: none` once Alpine has booted, so a full page load painted the whole
// nav panel over the dashboard for a frame — most visibly on the post-unlock
// redirect. `x-cloak` plus an owned backing rule is the fix.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'drawer-cloak-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

// Grabs the full opening tag of the first <div> with the given class, whatever
// the attribute order — a negated-'>' run captures every attribute, newlines too.
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
    // At >=1024px the drawer container IS the static sidebar, and the rule that
    // keeps it laid out is a single class — the same weight as [x-cloak], whose
    // injected copy comes later in the cascade and wins. The exemption has to
    // out-specify it or the desktop nav blanks until Alpine boots.
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $desktopBlock = strstr($css, '@media (min-width: 1024px)');

    expect($desktopBlock)->toContain(".drawer-container[x-cloak] {\n        display: block !important;\n    }");
});
