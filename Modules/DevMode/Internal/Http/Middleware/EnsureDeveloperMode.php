<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// 404 rather than 403 for non-developers: a 403 would confirm the route exists.
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
