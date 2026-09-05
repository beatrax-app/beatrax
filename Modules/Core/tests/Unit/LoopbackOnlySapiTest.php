<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Internal\Http\Middleware\LoopbackOnly;
use Modules\Core\Internal\Http\Middleware\PhpSapi;
use Modules\Core\Internal\Support\NetworkBoundary;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The mobile shell runs PHP in-process under the `embed` SAPI, which listens on
// no socket and so publishes no SERVER_ADDR. The gate read that absence as a
// server that never advertised its bind address and refused every request —
// on a phone that is the whole app, every route, from first launch.

// FrankenPHP, the runtime the self-host recipe ships, publishes no SERVER_ADDR
// either. It was not named, so it fell through to the closed branch and 404'd
// the entire self-hosted shape — the one the platform matrix calls shipped.

function loopbackOnly(
    PhpSapi|string $sapi,
    string $servedInterfaces = '',
    string $appUrl = 'https://beatrax.test',
): LoopbackOnly {
    $app = Mockery::mock(Application::class);
    // The console is exempt on its own; these cases are about a request that
    // is NOT the console reaching the gate.
    $app->shouldReceive('runningInConsole')->andReturnFalse();

    return new LoopbackOnly(
        $app,
        new NetworkBoundary(new Repository([
            'app' => ['url' => $appUrl],
            'selfhost' => ['served_interfaces' => $servedInterfaces],
        ])),
        $sapi instanceof PhpSapi ? $sapi->value : $sapi,
    );
}

function loopbackPassThrough(): Closure
{
    return fn (): Response => new Response('ok');
}

it('serves an in-process mobile request that carries no SERVER_ADDR', function (): void {
    $response = loopbackOnly(PhpSapi::Embed)->handle(new Request, loopbackPassThrough());

    expect($response->getStatusCode())->toBe(200);
});

it('serves the built-in server when the peer it is talking to is loopback', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '127.0.0.1']);

    expect(loopbackOnly(PhpSapi::CliServer)->handle($request, loopbackPassThrough())->getStatusCode())->toBe(200);
});

// `php artisan serve --host=0.0.0.0` publishes no SERVER_ADDR and binds every
// interface, so treating "omits a bind address" as "is local" let a machine on
// the same network reach every route.
it('refuses the built-in server when the peer is not loopback', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '192.168.178.66']);

    expect(fn () => loopbackOnly(PhpSapi::CliServer)->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

// Nothing to prove it with, so it is not assumed — unlike the in-process SAPI,
// which has no socket for anyone to arrive on in the first place.
it('refuses the built-in server when it cannot see who it is talking to', function (): void {
    expect(fn () => loopbackOnly(PhpSapi::CliServer)->handle(new Request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

// The reason the branch exists: a socket-serving SAPI that never said which
// interface it bound is not something to assume was loopback.
it('still refuses a socket-serving SAPI that omits SERVER_ADDR', function (): void {
    expect(fn () => loopbackOnly('fpm-fcgi')->handle(new Request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

// A SAPI this gate does not recognise gets nothing from the widening, whatever
// the peer or the Host looks like — being unrecognised IS the refusal.
it('refuses an unrecognised SAPI even from a loopback peer on a widened install', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'beatrax.example.com']);

    expect(fn () => loopbackOnly('fpm-fcgi', '192.168.1.50', 'https://beatrax.example.com')
        ->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

// The address, when present, is still the test — the SAPI exemption only covers
// its absence, so a mobile runtime handed a routable address is refused.
it('refuses a non-loopback SERVER_ADDR even under the in-process SAPI', function (): void {
    $request = new Request(server: ['SERVER_ADDR' => '192.168.178.66']);

    expect(fn () => loopbackOnly(PhpSapi::Embed)->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

it('serves a loopback SERVER_ADDR under the in-process SAPI', function (): void {
    $request = new Request(server: ['SERVER_ADDR' => '127.0.0.1']);

    expect(loopbackOnly(PhpSapi::Embed)->handle($request, loopbackPassThrough())->getStatusCode())->toBe(200);
});

it('serves a published bind address the install recorded itself as serving', function (): void {
    $request = new Request(server: ['SERVER_ADDR' => '192.168.1.50']);

    expect(loopbackOnly('fpm-fcgi', '192.168.1.50')->handle($request, loopbackPassThrough())->getStatusCode())
        ->toBe(200);
});

it('refuses a published bind address the install did not record', function (): void {
    $request = new Request(server: ['SERVER_ADDR' => '192.168.1.51']);

    expect(fn () => loopbackOnly('fpm-fcgi', '192.168.1.50')->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses a published bind address when the record is only a wildcard', function (): void {
    $request = new Request(server: ['SERVER_ADDR' => '192.168.1.50']);

    expect(fn () => loopbackOnly('fpm-fcgi', '0.0.0.0')->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

// The in-container probe and anything else dialling the runtime from the same
// machine: the peer is loopback, so the request never crossed a network.
it('serves FrankenPHP when the peer it is talking to is loopback', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '127.0.0.1']);

    expect(loopbackOnly(PhpSapi::FrankenPhp)->handle($request, loopbackPassThrough())->getStatusCode())
        ->toBe(200);
});

it('refuses FrankenPHP from a remote peer while the boundary is closed', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.9', 'HTTP_HOST' => 'beatrax.example.com']);

    expect(fn () => loopbackOnly(PhpSapi::FrankenPhp, '', 'https://beatrax.example.com')
        ->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

it('serves FrankenPHP from a remote peer under the recorded host once widened', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.9', 'HTTP_HOST' => 'beatrax.example.com']);

    expect(loopbackOnly(PhpSapi::FrankenPhp, '192.168.1.50', 'https://beatrax.example.com')
        ->handle($request, loopbackPassThrough())->getStatusCode())
        ->toBe(200);
});

// The whole reason the recorded host has to name something past loopback: a
// caller on the LAN chooses its own Host header, and `localhost` is the one it
// would choose.
it('refuses a remote peer spelling its Host as localhost on a widened install', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.9', 'HTTP_HOST' => 'localhost']);

    expect(fn () => loopbackOnly(PhpSapi::FrankenPhp, '192.168.1.50', 'https://beatrax.example.com')
        ->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses a remote peer under a host the install never recorded', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.9', 'HTTP_HOST' => 'evil.example']);

    expect(fn () => loopbackOnly(PhpSapi::FrankenPhp, '192.168.1.50', 'https://beatrax.example.com')
        ->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses a remote peer while APP_URL still names loopback, however widened', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.9', 'HTTP_HOST' => 'localhost']);

    expect(fn () => loopbackOnly(PhpSapi::FrankenPhp, '192.168.1.50', 'http://localhost:8000')
        ->handle($request, loopbackPassThrough()))
        ->toThrow(NotFoundHttpException::class);
});

it('serves the built-in server from a remote peer under the recorded host once widened', function (): void {
    $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.9', 'HTTP_HOST' => 'beatrax.example.com']);

    expect(loopbackOnly(PhpSapi::CliServer, '192.168.1.50', 'https://beatrax.example.com')
        ->handle($request, loopbackPassThrough())->getStatusCode())
        ->toBe(200);
});
