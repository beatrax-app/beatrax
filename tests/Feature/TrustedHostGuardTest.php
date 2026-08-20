<?php

declare(strict_types=1);
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Internal\Http\Middleware\TrustedHostGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The guard is the defence against a DNS-rebinding site reaching the loopback
// server as a same-origin caller. The suite itself runs under the APP_URL host
// (beatrax.test), so the configured-host case doubles as proof the guard does
// not break ordinary requests.
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

/*
 * The Android shell forwards no Host header at all. Its bridge sends Cookie,
 * Accept, User-Agent, Referer and the sec-ch-ua set — captured from a device
 * — and nothing else, so Request::getHost() returns ''. Rejecting that took
 * the entire app down: every route 404'd, including Laravel's own /up, while
 * the runtime booted cleanly in 469ms and rendered the app's styled 404.
 *
 * Rebinding works by NAMING a domain, and a browser always sends Host on
 * HTTP/1.1, so an empty one cannot carry the attack this gate exists to stop.
 * LoopbackOnly still gates the interface the request arrived on.
 */
it('allows a request with no Host header, as the Android shell sends', function (): void {
    $guard = app(TrustedHostGuard::class);

    $request = Request::create('/goals', 'GET');
    $request->headers->remove('Host');
    $request->server->remove('HTTP_HOST');
    $request->server->remove('SERVER_NAME');
    $request->server->remove('SERVER_ADDR');

    expect($request->getHost())->toBe('');

    $response = $guard->handle($request, fn (): Response => new Response('reached the app'));

    expect($response->getContent())->toBe('reached the app');
});

it('still refuses a named foreign Host once the empty case is allowed', function (): void {
    $guard = app(TrustedHostGuard::class);

    $request = Request::create('http://evil.example.com/goals', 'GET');

    expect(fn () => $guard->handle($request, fn (): Response => new Response('reached the app')))
        ->toThrow(NotFoundHttpException::class);
});

// A blank Host normalises to the same empty string as no Host at all, so the
// guard cannot tell them apart and does not pretend to. Neither names a
// domain, which is the only thing rebinding can exploit.
it('treats a blank Host the same as none, since Symfony normalises both to empty', function (): void {
    $request = Request::create('/goals', 'GET');
    $request->headers->set('Host', ' ');

    expect($request->getHost())->toBe('');

    $response = app(TrustedHostGuard::class)
        ->handle($request, fn (): Response => new Response('reached the app'));

    expect($response->getContent())->toBe('reached the app');
});

it('refuses a foreign Host that differs only by case', function (): void {
    $guard = app(TrustedHostGuard::class);

    $request = Request::create('/goals', 'GET');
    $request->headers->set('Host', 'EVIL.example.com');

    expect(fn () => $guard->handle($request, fn (): Response => new Response('reached the app')))
        ->toThrow(NotFoundHttpException::class);
});
