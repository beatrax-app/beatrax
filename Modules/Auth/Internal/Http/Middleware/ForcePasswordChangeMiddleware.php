<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

final readonly class ForcePasswordChangeMiddleware
{
    /**
     * @var list<string>
     */
    private const ALLOWED_ROUTE_NAMES = ['auth.change-password', 'logout'];

    public function __construct(
        private CurrentUser $currentUser,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            $user = $this->currentUser->user();
            $routeName = $request->route()?->getName();

            if ($user->force_password_change_at_next_login
                && ! in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
                return new RedirectResponse($this->urls->route('auth.change-password'));
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
