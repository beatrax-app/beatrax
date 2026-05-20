<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Restricts a route to the bootstrap window before the first account is
 * created.
 *
 * The signup ceremony only exists while the device has no users. Once a
 * user row exists the route this middleware guards is no longer a real
 * surface, so the middleware throws a 404 rather than a 403 — a prober
 * cannot distinguish "signup never existed here" from "signup is now
 * closed".
 */
final readonly class FirstUserOnlyMiddleware
{
    public function __construct(private DatabaseManager $db) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->db->connection()->table('users')->count() > 0) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
