<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Livewire\Features\SupportRedirects\Redirector as LivewireRedirector;
use Modules\Mobile\Internal\Http\Middleware\RestoreFrameworkRedirector;
use Symfony\Component\HttpFoundation\Response;

/*
 * Livewire swaps the container's redirector while a component is booted and
 * swaps it back on dehydrate. A request that dies between the two leaves its
 * own installed, and `Livewire\...\Redirector::to()` returns `$this` rather
 * than a response.
 *
 * Under PHP-FPM that dies with the process. The mobile runtime is persistent,
 * so it survives: measured on an iPhone, every subsequent GET /cash answered
 * 500 "Undefined property: …Redirector::$headers" from the CSRF middleware,
 * and only relaunching the app cleared it.
 */

it('restores the framework redirector when Livewire left its own behind', function (): void {
    app()->instance('redirect', new LivewireRedirector(app('url')));

    expect(app('redirect')::class)->not->toBe(Redirector::class);

    $reached = false;
    app(RestoreFrameworkRedirector::class)->handle(
        Request::create('/cash', 'GET'),
        function () use (&$reached): Response {
            $reached = true;

            return new Response('ok');
        },
    );

    expect($reached)->toBeTrue()
        ->and(app('redirect')::class)->toBe(Redirector::class);
});

it('leaves a redirect usable as a response again', function (): void {
    app()->instance('redirect', new LivewireRedirector(app('url')));

    app(RestoreFrameworkRedirector::class)->handle(
        Request::create('/cash', 'GET'),
        static fn (): Response => new Response('ok'),
    );

    // The actual failure: to() answered with the redirector itself, so the
    // CSRF middleware read ->headers off something that has none.
    $redirect = app('redirect')->to('/login');

    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->headers->get('Location'))->toContain('/login');
});

it('leaves the framework redirector alone when nothing swapped it', function (): void {
    $before = app('redirect');

    app(RestoreFrameworkRedirector::class)->handle(
        Request::create('/cash', 'GET'),
        static fn (): Response => new Response('ok'),
    );

    // Rebinding on every request would drop a session the framework had
    // already attached, so the guard has to be a no-op in the normal case.
    expect(app('redirect'))->toBe($before);
});

it('runs before anything else in the mobile stack', function (): void {
    $bootstrap = (string) file_get_contents(
        is_file(base_path('mobile-app/bootstrap/app.php'))
            ? base_path('mobile-app/bootstrap/app.php')
            : base_path('bootstrap/app.php')
    );

    // It repairs a binding the middleware after it depend on, so appending it
    // would leave exactly the requests it exists for still failing.
    expect($bootstrap)->toContain('prepend(RestoreFrameworkRedirector::class)');
});
