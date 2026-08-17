<?php

declare(strict_types=1);

/**
 * Feature tests proving the global TrustedHostGuard middleware answers only
 * to the loopback names the bundled shells use and the host baked into
 * APP_URL, and 404s every other Host — the defence that keeps a
 * DNS-rebinding site from reaching the loopback server as a same-origin
 * caller. The test suite runs under the APP_URL host (beatrax.test), so the
 * configured-host case doubles as the guarantee the guard does not break
 * ordinary requests.
 */
it('refuses a foreign Host with 404', function (): void {
    $this->get('http://evil.example/login')->assertNotFound();
});

it('refuses a look-alike Host that only suffix-matches the app host', function (): void {
    $this->get('http://beatrax.test.evil.example/login')->assertNotFound();
});

it('allows the configured APP_URL host', function (): void {
    $response = $this->get('http://beatrax.test/login');
    expect($response->status())->not->toBe(404);
});

it('allows localhost', function (): void {
    $response = $this->get('http://localhost/login');
    expect($response->status())->not->toBe(404);
});

it('allows 127.0.0.1 (IPv4 loopback)', function (): void {
    $response = $this->get('http://127.0.0.1/login');
    expect($response->status())->not->toBe(404);
});

it('allows ::1 (IPv6 loopback)', function (): void {
    $response = $this->withServerVariables(['HTTP_HOST' => '[::1]'])->get('http://[::1]/login');
    expect($response->status())->not->toBe(404);
});
