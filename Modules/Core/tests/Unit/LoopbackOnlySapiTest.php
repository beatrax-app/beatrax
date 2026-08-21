<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Internal\Http\Middleware\LoopbackOnly;
use Modules\Core\Internal\Http\Middleware\PhpSapi;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The mobile shell runs PHP in-process under the `embed` SAPI, which listens on
// no socket and so publishes no SERVER_ADDR. The gate read that absence as a
// server that never advertised its bind address and refused every request —
// on a phone that is the whole app, every route, from first launch.

function loopbackOnly(PhpSapi|string $sapi): LoopbackOnly
{
    $app = Mockery::mock(Application::class);
    // The console is exempt on its own; these cases are about a request that
    // is NOT the console reaching the gate.
    $app->shouldReceive('runningInConsole')->andReturnFalse();

    return new LoopbackOnly($app, $sapi instanceof PhpSapi ? $sapi->value : $sapi);
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
