<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Models\User;

/*
 * Brand SVG render invariant.
 *
 *  The authenticated sidebar renders the brand SVG (the file shipped
 *  at resources/brand/logo.svg, published through Vite to
 *  public/build/assets/logo-<hash>.svg) inside the .side-brand slot —
 *  not the placeholder text "b" glyph that previously occupied the
 *  spot. The rendered <img> carries alt="Beatrax", width/height 24,
 *  and the `logo-svg` class so the dedicated CSS rule
 *  (.side-brand .logo-svg) can size and round it.
 */

it('renders the brand svg in the authenticated sidebar', function (): void {
    $user = User::query()->create([
        'username' => 'brand-svg-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => false,
    ]);

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    // The rendered <img> src must resolve through Vite's hashed
    // build path — the regex matches /build/assets/logo-<hash>.svg
    // (manifest entry written by `npm run build` from the
    // resources/brand/logo.svg input).
    expect($html)->toMatch('#/build/assets/logo-[A-Za-z0-9_-]+\.svg#');
    expect($html)->toContain('alt="Beatrax"');
    expect($html)->toContain('logo-svg');

    // The pre-swap placeholder must be gone — guards against an
    // accidental revert of the brand row.
    expect($html)->not->toContain('aria-hidden="true">b</span>');
});
