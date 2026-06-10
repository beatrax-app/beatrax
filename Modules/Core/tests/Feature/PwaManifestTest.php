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
