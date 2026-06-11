<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * PWA-02 manifest + icon route assertions (Wave 0 RED stubs).
 *
 * These tests will FAIL until Wave 1 (04-02) creates the
 * /site.webmanifest route and the /icons/ public icon set.
 *
 * Assertions cover:
 *   - GET /site.webmanifest returns 200 JSON containing "beatrax"
 *   - GET /icons/icon-192.png returns 200
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'pwa-manifest-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('/site.webmanifest returns 200', function (): void {
    $response = $this->get('/site.webmanifest');
    $response->assertOk();
});

it('/site.webmanifest response body contains the app name beatrax', function (): void {
    $response = $this->get('/site.webmanifest');
    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->toContain('beatrax');
});

it('/icons/icon-192.png returns 200', function (): void {
    $response = $this->get('/icons/icon-192.png');
    $response->assertOk();
});

it('/site.webmanifest icons array includes apple-touch-icon entry', function (): void {
    $response = $this->get('/site.webmanifest');
    $response->assertOk();
    $manifest = json_decode((string) $response->getContent(), true);
    $icons = $manifest['icons'] ?? [];
    $appleTouchIcon = array_filter($icons, static fn (array $icon): bool => ($icon['src'] ?? '') === '/icons/apple-touch-icon.png');
    expect($appleTouchIcon)->not->toBeEmpty();
    $entry = array_values($appleTouchIcon)[0];
    expect($entry['sizes'])->toBe('180x180');
    expect($entry['type'])->toBe('image/png');
});
