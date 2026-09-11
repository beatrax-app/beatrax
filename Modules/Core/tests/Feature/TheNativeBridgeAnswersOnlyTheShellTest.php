<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ShellBridge;

uses(RefreshDatabase::class);

// NativePHP registers _native/api/* on every deployment -- hasRoute('api') is
// unconditional -- and its PreventRegularBrowserAccess returns early when the
// shell is not running. The route is also declared withoutMiddleware(CSRF), and
// its controller does `new $event(...$payload)` on what the request named.
it('does not answer the event bridge where nothing can show the shell secret', function (): void {
    config()->set('nativephp-internal.secret', null);

    $this->post('/_native/api/events', [
        'event' => 'Illuminate\\Foundation\\Events\\Terminating',
        'payload' => [],
    ])->assertNotFound();
});

it('does not answer the booted or cookie routes either', function (): void {
    config()->set('nativephp-internal.secret', null);

    $this->post('/_native/api/booted')->assertNotFound();
    $this->get('/_native/api/cookie')->assertNotFound();
});

// The positive control. Without it a guard that refused every path in the app
// would read exactly the same way as one that refused this prefix.
it('leaves every other route alone', function (): void {
    config()->set('nativephp-internal.secret', null);

    // Whatever /login answers on an empty database -- it redirects to setup --
    // the one answer that would mean this guard ate it is 404.
    expect($this->get('/login')->status())->not->toBe(404);
});

// Inside a bundle the secret exists, so a browser aimed at the same port is
// the caller this closes on. 404 rather than the package's 403: a wrong
// answer that admits the door is there is what the rest of this app avoids.
it('answers a browser inside the bundle exactly as it answers one outside', function (): void {
    ShellBridge::arm();
    config()->set('nativephp-internal.running', true);

    $this->post('/_native/api/events', [
        'event' => 'Illuminate\\Foundation\\Events\\Terminating',
        'payload' => [],
    ])->assertNotFound();
});

// And the shell itself still reaches its own bridge, which is the arrangement
// this restores rather than replaces.
it('hands the bridge back to the package once the caller carries the secret', function (): void {
    $headers = ShellBridge::arm();

    $this->withHeaders($headers)->post('/_native/api/events', [
        'event' => 'Illuminate\\Foundation\\Events\\Terminating',
        'payload' => [],
    ])->assertOk();
});

// A near-miss must not pass: hash_equals is the comparison, and a guard that
// used str_contains or a loose == would let this through.
it('refuses a secret that is only nearly right', function (): void {
    $headers = ShellBridge::arm();

    $this->withHeaders([ShellBridge::HEADER => $headers[ShellBridge::HEADER].'x'])
        ->post('/_native/api/events', [
            'event' => 'Illuminate\\Foundation\\Events\\Terminating',
            'payload' => [],
        ])->assertNotFound();
});

// The shipped shell stamps the secret onto every request ADDRESSED to the PHP
// port -- `onBeforeSendHeaders` filters on the destination URL, not on who
// asked -- so the bank consent screen the open-banking flow navigates this same
// window to presents the credential the test above assumed only the shell had.
it('refuses a page the shell is hosting, secret and all', function (): void {
    $headers = ShellBridge::arm();
    config()->set('nativephp-internal.running', true);

    $this->withHeaders($headers + [
        'Origin' => 'https://consent.example-bank.test',
        'Sec-Fetch-Site' => 'cross-site',
        'Sec-Fetch-Mode' => 'no-cors',
    ])->post('/_native/api/events', [
        'event' => 'Illuminate\\Foundation\\Events\\Terminating',
        'payload' => [],
    ])->assertNotFound();
});

// And the app's own page, which is stamped identically. A same-origin caller is
// the one this gate was always read as admitting, and it is the one an injected
// script would be.
it('refuses the app own page too', function (): void {
    $headers = ShellBridge::arm();
    config()->set('nativephp-internal.running', true);

    $this->withHeaders($headers + [
        'Origin' => 'http://127.0.0.1:8100',
        'Sec-Fetch-Site' => 'same-origin',
    ])->post('/_native/api/events', [
        'event' => 'Illuminate\\Foundation\\Events\\Terminating',
        'payload' => [],
    ])->assertNotFound();
});

// A GET carries no Origin, so Sec-Fetch-Site is the only thing separating this
// from the shell -- and it is the request that would hand a page the secret in
// a cookie it can read.
it('refuses the cookie route to a page that carries the secret', function (): void {
    $headers = ShellBridge::arm();
    config()->set('nativephp-internal.running', true);

    $this->withHeaders($headers + ['Sec-Fetch-Site' => 'same-origin'])
        ->get('/_native/api/cookie')
        ->assertNotFound();
});

// The cookie was a second copy of the secret, planted raw by the shell and not
// marked httpOnly. Nothing can present it except a browsing context, and those
// are refused above, so the header is the whole credential now.
it('does not take the shell cookie as proof of anything', function (): void {
    ShellBridge::arm();
    config()->set('nativephp-internal.running', true);

    $this->withUnencryptedCookie('_php_native', 'shell-bridge-secret-for-tests')
        ->post('/_native/api/events', [
            'event' => 'Illuminate\\Foundation\\Events\\Terminating',
            'payload' => [],
        ])->assertNotFound();
});
