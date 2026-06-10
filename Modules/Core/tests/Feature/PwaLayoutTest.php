<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * PWA-01 layout meta-tag assertions (Wave 0 RED stubs).
 *
 * These tests will FAIL until Wave 1 (04-02) extends the authenticated
 * layout with the PWA head block and SW registration script.
 *
 * Assertions cover:
 *   - viewport-fit=cover in the viewport meta
 *   - /site.webmanifest <link> in the layout head
 *   - two theme-color meta tags (prefers-color-scheme: light + dark)
 *   - navigator.serviceWorker.register('/sw.js') in the layout
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'pwa-layout-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('renders viewport-fit=cover in the authenticated layout', function (): void {
    $response = $this->actingAs($this->user)->get('/');
    $response->assertOk();
    $response->assertSee('viewport-fit=cover', escape: false);
});

it('renders the manifest link in the authenticated layout', function (): void {
    $response = $this->actingAs($this->user)->get('/');
    $response->assertOk();
    $response->assertSee('/site.webmanifest', escape: false);
});

it('renders two theme-color meta tags in the layout head', function (): void {
    $response = $this->actingAs($this->user)->get('/');
    $html = (string) $response->getContent();
    expect($html)->toContain('prefers-color-scheme: light');
    expect($html)->toContain('prefers-color-scheme: dark');
});

it('renders the SW registration script in the layout', function (): void {
    $response = $this->actingAs($this->user)->get('/');
    $html = (string) $response->getContent();
    expect($html)->toContain("navigator.serviceWorker.register('/sw.js'");
});
