<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Pins frame-ancestors to the current origin on the embedded Horizon
// page so a hostile external page cannot wrap /dev/horizon in its own
// attacker-controlled frame; pairs with the iframe sandbox/referrerpolicy
// attributes on the Livewire view. Bound onto dev.horizon only.
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
