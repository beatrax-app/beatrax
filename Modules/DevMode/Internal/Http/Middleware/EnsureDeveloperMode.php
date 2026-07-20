<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Non-developers (incl. unauthenticated) receive a 404 rather than a
// 403, so the route's existence is not disclosed. Every `/dev/*` route
// must apply the `ensureDeveloperMode` alias — the arch invariant
// everyDevModeRouteAppliesEnsureDeveloperModeMiddleware locks that at PR time.
final readonly class EnsureDeveloperMode
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
