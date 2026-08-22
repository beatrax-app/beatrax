<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Core\Public\Http\Middleware\AfterResponseMiddleware;
use Symfony\Component\HttpFoundation\Response;

// Two middleware defer work past the response, and both used to restate the
// same pass-through handle() and the same terminate() signature. The signature
// is the kernel's, not ours: Laravel decides a middleware is terminable by
// finding the method, and hands it a request and a response deferred work does
// not read. Declared once here so neither half can drift.

function afterResponseSpy(stdClass $log): AfterResponseMiddleware
{
    return new readonly class($log) extends AfterResponseMiddleware
    {
        public function __construct(private stdClass $log) {}

        protected function afterResponse(): void
        {
            $this->log->calls++;
        }
    };
}

// This is what makes a middleware terminable — Illuminate's kernel asks
// method_exists($instance, 'terminate') and nothing else. A subclass that
// inherits it is as terminable as one that declares it, which is the whole
// reason the base class can own the signature.
it('is terminable by the test the kernel actually applies', function (): void {
    $log = new stdClass;
    $log->calls = 0;

    expect(method_exists(afterResponseSpy($log), 'terminate'))->toBeTrue();
});

// handle() hands back exactly what the stack returned. A pass-through that
// substitutes its own response would silently discard headers and status set
// further in, and nothing downstream would report it.
it('returns the response the rest of the stack produced, unchanged', function (): void {
    $log = new stdClass;
    $log->calls = 0;
    $response = new Response('body', 418, ['X-Marker' => 'kept']);

    $returned = afterResponseSpy($log)->handle(
        Request::create('/anything'),
        static fn (): Response => $response,
    );

    expect($returned)->toBe($response)
        ->and($returned->getStatusCode())->toBe(418)
        ->and($returned->headers->get('X-Marker'))->toBe('kept');
});

// The work must not run on the way in. Both subclasses exist so that a browse
// that burns its timeout, or a full history re-projection, is paid after the
// response has gone out rather than in front of a page somebody is waiting for.
it('does not do the deferred work while handling the request', function (): void {
    $log = new stdClass;
    $log->calls = 0;

    afterResponseSpy($log)->handle(
        Request::create('/anything'),
        static fn (): Response => new Response,
    );

    expect($log->calls)->toBe(0);
});

it('runs the deferred work once when the kernel terminates', function (): void {
    $log = new stdClass;
    $log->calls = 0;
    $middleware = afterResponseSpy($log);

    $middleware->terminate(Request::create('/anything'), new Response);

    expect($log->calls)->toBe(1);

    $middleware->terminate(Request::create('/anything'), new Response);

    expect($log->calls)->toBe(2);
});
