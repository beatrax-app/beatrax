<?php

declare(strict_types=1);

use Modules\Core\Models\User;

// These read /help/data-locations rather than /: the dashboard redirects to
// /imports/new while there are no transactions, so it cannot be relied on to
// render the authenticated layout at all in an empty test database.

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
