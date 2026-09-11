<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Bound onto dev.horizon only. The app-wide policy is frame-ancestors 'none';
// this route widens it to 'self' for the Dev Console's own embed and no
// further, so a hostile page still cannot wrap it. Pairs with the iframe
// attributes on the view.
final class HorizonFrameAncestors
{
    // frame-ancestors ALONE, never a whole policy. This runs inside the global
    // middleware that owns the base CSP, which merges this one directive over
    // its own and drops anything else: a route that wrote more here would lose
    // the extra rather than displace the nine directives it did not write.
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self'",
        );

        return $response;
    }
}
