<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds strict no-store cache-control headers to every response that passes
 * through this middleware. Prevents the browser from caching authenticated
 * finance pages where the back button could otherwise reveal sensitive
 * balances after sign-out.
 */
final class NoStoreFinancialData
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
