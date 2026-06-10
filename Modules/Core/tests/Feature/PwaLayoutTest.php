<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * PWA-01 layout meta-tag assertions.
 *
 * Wave 0 stubs turned GREEN in Wave 1 (04-02) by extending the authenticated
 * layout with the PWA head block and SW registration script.
 *
 * Assertions cover:
 *   - viewport-fit=cover in the viewport meta
 *   - /site.webmanifest <link> in the layout head
 *   - two theme-color meta tags (prefers-color-scheme: light + dark)
 *   - navigator.serviceWorker.register('/sw.js') in the layout
 *
 * Uses /help/data-locations (not /) — the dashboard redirects to
 * /imports/new when there are no transactions yet (isFirstRun), so it
 * is not a reliable layout-render URL in an empty test DB. The data-
 * locations help page is a plain Route::view() that always renders
 * the authenticated layout (resources/views/layouts/app.blade.php)
 * with no redirect conditions and no user-attribute requirements.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'pwa-layout-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('renders viewport-fit=cover in the authenticated layout', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $response->assertOk();
    $response->assertSee('viewport-fit=cover', escape: false);
});

it('renders the manifest link in the authenticated layout', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $response->assertOk();
    $response->assertSee('/site.webmanifest', escape: false);
});

it('renders two theme-color meta tags in the layout head', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $html = (string) $response->getContent();
    expect($html)->toContain('prefers-color-scheme: light');
    expect($html)->toContain('prefers-color-scheme: dark');
});

it('renders the SW registration script in the layout', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $html = (string) $response->getContent();
    expect($html)->toContain("navigator.serviceWorker.register('/sw.js'");
});
