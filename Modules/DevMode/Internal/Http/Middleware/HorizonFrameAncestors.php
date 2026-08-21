<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Bound onto dev.horizon only, so a hostile page cannot wrap the embedded
// Horizon UI in its own frame. Pairs with the iframe attributes on the view.
final class HorizonFrameAncestors
{
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
