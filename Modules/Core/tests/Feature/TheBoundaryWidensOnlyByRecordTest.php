<?php

declare(strict_types=1);

use Modules\Core\Internal\Support\NetworkBoundary;

// The platform matrix calls the self-hosted shape shipped and "reached over
// their own network", and the address gate refused every non-loopback request
// with no carve-out at all. These run the gate through the real HTTP stack,
// where LoopbackOnly and TrustedHostGuard are both prepended.

it('ships with nothing recorded, so the boundary is loopback-only out of the box', function (): void {
    $boundary = $this->app->make(NetworkBoundary::class);

    expect($boundary->servedInterfaces())->toBe([]);
    expect($boundary->refusedInterfaces())->toBe([]);
    expect($boundary->isWidened())->toBeFalse();

    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
        ->get('/login')
        ->assertNotFound();
});

it('serves an interface the install recorded itself as serving', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.10']);

    $response = $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])->get('/login');

    expect($response->status())->not->toBe(404);
});

it('still refuses every interface the install did not record', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.10']);

    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.11'])
        ->get('/login')
        ->assertNotFound();

    $this->withServerVariables(['SERVER_ADDR' => '10.0.0.4'])
        ->get('/login')
        ->assertNotFound();
});

it('serves a recorded interface published in the other address family spelling', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.10']);

    $response = $this->withServerVariables(['SERVER_ADDR' => '::ffff:192.168.1.10'])->get('/login');

    expect($response->status())->not->toBe(404);
});

// The setting names interfaces. A wildcard names none of them, and "all of
// them" is the one meaning it must never be able to carry.
it('refuses a wildcard record and serves nothing on account of it', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '0.0.0.0']);

    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
        ->get('/login')
        ->assertNotFound();

    expect($this->app->make(NetworkBoundary::class)->refusedInterfaces())->toBe(['0.0.0.0']);
});

it('refuses a CIDR record and serves no address inside the range', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.0/24']);

    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
        ->get('/login')
        ->assertNotFound();
});

// TrustedHostGuard runs first and answers from the same object, so a widened
// interface does not admit a Host the install never recorded.
it('refuses a foreign Host on a widened install, at the gate that runs first', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.10']);

    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
        ->get('http://evil.example/login')
        ->assertNotFound();
});

it('admits the recorded APP_URL host on a widened interface', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.10']);

    $response = $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
        ->get('http://beatrax.test/login');

    expect($response->status())->not->toBe(404);
});
