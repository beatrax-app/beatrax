<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\DevConsoleBuildGate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class EnsureDeveloperMode
{
    public function __construct(
        private CurrentUser $currentUser,
        private DevConsoleBuildGate $build,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->build->permits()
            || ! $this->currentUser->isAuthenticated()
            || $this->currentUser->user()->is_developer !== true) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
