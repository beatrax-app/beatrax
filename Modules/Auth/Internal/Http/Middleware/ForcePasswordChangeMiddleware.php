<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the force_password_change_at_next_login flag.
 *
 * When an authenticated user carries the flag, every request is
 * redirected to the change-password page until they replace their
 * password. The change-password page itself and the logout route are
 * exempt by route name so the user can complete the change or sign out
 * without being trapped in a redirect loop.
 *
 * The current user is read through the CurrentUser contract — the DI
 * seam — never the Auth facade or the auth() helper.
 */
final readonly class ForcePasswordChangeMiddleware
{
    /** @var list<string> */
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
