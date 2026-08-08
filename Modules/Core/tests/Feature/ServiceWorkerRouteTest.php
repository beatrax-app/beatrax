<?php

declare(strict_types=1);

/*
 * PWA-03 service-worker route assertions (Wave 0 RED stubs).
 *
 * These tests will FAIL until Wave 1 (04-02) creates the /sw.js
 * unauthenticated route serving the Blade-rendered service worker.
 *
 * Assertions cover:
 *   - GET /sw.js returns 200 without authentication
 *   - Content-Type is application/javascript
 *   - Response body contains the Beatrax cache name prefix
 *   - Response body contains the version from config('nativephp.version')
 */

it('/sw.js is publicly accessible without authentication', function (): void {
    $response = $this->get('/sw.js');
    $response->assertOk();
});

it('/sw.js returns Content-Type application/javascript', function (): void {
    $response = $this->get('/sw.js');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/javascript');
});

it('/sw.js contains the Beatrax cache name prefix', function (): void {
    $html = (string) $this->get('/sw.js')->getContent();
    expect($html)->toContain('beatrax-shell-v');
});

it('/sw.js contains the version from config(nativephp.version)', function (): void {
    $version = config('nativephp.version');
    $html = (string) $this->get('/sw.js')->getContent();
    expect($html)->toContain($version);
});
