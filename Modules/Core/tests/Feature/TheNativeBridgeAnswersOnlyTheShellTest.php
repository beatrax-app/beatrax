<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// NativePHP registers _native/api/* on every deployment -- hasRoute('api') is
// unconditional -- and its PreventRegularBrowserAccess returns early when the
// shell is not running. The route is also declared withoutMiddleware(CSRF), and
// its controller does `new $event(...$payload)` on what the request named.
it('does not answer the event bridge where the shell is not running', function (): void {
    config()->set('nativephp-internal.running', false);

    $this->post('/_native/api/events', [
        'event' => 'Illuminate\\Foundation\\Events\\Terminating',
        'payload' => [],
    ])->assertNotFound();
});

it('does not answer the booted or cookie routes either', function (): void {
    config()->set('nativephp-internal.running', false);

    $this->post('/_native/api/booted')->assertNotFound();
    $this->get('/_native/api/cookie')->assertNotFound();
});

// The positive control. Without it a guard that refused every path in the app
// would read exactly the same way as one that refused this prefix.
it('leaves every other route alone', function (): void {
    config()->set('nativephp-internal.running', false);

    // Whatever /login answers on an empty database -- it redirects to setup --
    // the one answer that would mean this guard ate it is 404.
    expect($this->get('/login')->status())->not->toBe(404);
});

// And the shell itself still reaches its own bridge: the package's guard takes
// over there, which is the arrangement this restores rather than replaces.
it('hands the bridge back to the package once the shell is running', function (): void {
    config()->set('nativephp-internal.running', true);

    $this->post('/_native/api/events', [
        'event' => 'Illuminate\\Foundation\\Events\\Terminating',
        'payload' => [],
    ])->assertForbidden();
});
