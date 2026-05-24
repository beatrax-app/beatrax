<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates the in-app Developer Console at `/dev/*`.
 *
 * Non-developers (including unauthenticated requests) receive a 404
 * NotFoundHttpException rather than a 403, so the route's existence is
 * not disclosed to a partner-account user or an anonymous probe. The
 * current user is read through the CurrentUser contract — never the
 * Auth facade or the auth() helper — so the DI-only invariant the
 * project enforces stays intact.
 *
 * The Modules/DevMode/Routes/web.php route group attaches this
 * middleware via the `ensureDeveloperMode` alias registered in
 * DevModeServiceProvider::boot(); every `/dev/*` route MUST apply the
 * alias. The arch invariant
 * `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` (in
 * tests/Contracts/BoundaryArchTest.php) locks the coverage at PR time.
 */
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
