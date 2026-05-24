<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins frame-ancestors to the current origin on the embedded Horizon
 * page response so a hostile external page cannot wrap /dev/horizon
 * (or the dashboard it iframes) in its own attacker-controlled frame.
 *
 * Pairs with the iframe `sandbox` + `referrerpolicy` attributes on the
 * Livewire view: the Content-Security-Policy header blocks framing of
 * the outer Livewire page from a third-party origin, the iframe
 * attributes constrain what the embedded Horizon dashboard can do.
 *
 * Single-purpose middleware bound onto the dev.horizon route only so
 * the policy footprint is minimal — other dev surfaces stay free to
 * be iframed for local debugging.
 */
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
