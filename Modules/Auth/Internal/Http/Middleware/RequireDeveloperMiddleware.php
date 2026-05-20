<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Restricts a route to developer (owner) users.
 *
 * A request from a non-developer — or from no user at all — raises a 404
 * NotFoundHttpException rather than a 403, so a non-developer cannot
 * confirm the route exists. The current user is read through the
 * CurrentUser contract, never the Auth facade or the auth() helper.
 */
final readonly class RequireDeveloperMiddleware
{
    public function __construct(private CurrentUser $currentUser) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->currentUser->isAuthenticated() || $this->currentUser->user()->is_developer !== true) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
