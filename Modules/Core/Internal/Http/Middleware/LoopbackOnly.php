<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Refuses any request whose `SERVER_ADDR` is not a loopback address. Throws a
 * 404 NotFoundHttpException so the application never advertises its existence
 * to non-loopback callers. Requests without `SERVER_ADDR` (CLI invocations,
 * Pest fixtures that have not set the variable explicitly) pass through.
 */
final class LoopbackOnly
{
    private const LOOPBACK_ADDRESSES = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        $serverAddr = $request->server('SERVER_ADDR');

        if (is_string($serverAddr) && ! in_array($serverAddr, self::LOOPBACK_ADDRESSES, true)) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
