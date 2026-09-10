<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Modules\Core\Internal\Http\Middleware\NoStoreFinancialData;
use Symfony\Component\HttpFoundation\Response;

// Every route middleware runs inside this one, so whatever it left on the
// response arrived after the base policy could have been written and before it
// was. Deferring to it handed one route the whole header.

function policyMergeMiddleware(): NoStoreFinancialData
{
    return new NoStoreFinancialData(app(Vite::class), app());
}

function policyMergeOver(?string $declared): string
{
    $response = policyMergeMiddleware()->handle(
        Request::create('/dev/horizon'),
        static function () use ($declared): Response {
            $inner = new Response('ok');

            if ($declared !== null) {
                $inner->headers->set('Content-Security-Policy', $declared);
            }

            return $inner;
        },
    );

    return (string) $response->headers->get('Content-Security-Policy');
}

it('writes the whole base policy for a response that declared nothing', function (): void {
    // The control. It passes however the merge behaves, so a base directive
    // missing below is the merge and not a policy that was never built.
    $csp = policyMergeOver(null);

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'");
});

it('keeps every base directive a route did not write when the route writes one', function (): void {
    $csp = policyMergeOver("frame-ancestors 'self'");

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'")
        ->and($csp)->toContain("frame-ancestors 'self'");
});

it('drops a directive a route is in no position to relax', function (): void {
    $csp = policyMergeOver("script-src 'unsafe-inline' *; default-src *; frame-ancestors 'self'");

    expect($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->not->toContain("script-src 'unsafe-inline'")
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->not->toContain('default-src *')
        // The one it may write still lands, so this is the allow-list working
        // rather than the whole declaration being thrown away.
        ->and($csp)->toContain("frame-ancestors 'self'");
});

it('reads an empty source list as having said nothing', function (): void {
    // A directive with no sources is a parse error the browser answers by
    // ignoring it, which for frame-ancestors means framed by anyone.
    expect(policyMergeOver('frame-ancestors'))->toContain("frame-ancestors 'none'");
});

it('withdraws X-Frame-Options against a policy that carries a frame rule', function (): void {
    $response = policyMergeMiddleware()->handle(
        Request::create('/dev/horizon'),
        static function (): Response {
            $inner = new Response('ok');
            $inner->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

            return $inner;
        },
    );

    expect($response->headers->has('X-Frame-Options'))->toBeFalse()
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors')
        // The rest of the baseline still applies; only the conflicting one goes.
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});
