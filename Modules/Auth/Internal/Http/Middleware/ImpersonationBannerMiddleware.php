<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paints the persistent profile-switch banner on every authenticated
 * page while a switch is active.
 *
 * The presence of the `auth.impersonating.original_user_id` session
 * key is the on/off flag. When set, the middleware shares
 * `impersonatingPartnerUsername` with the view layer — the username of
 * the user currently being acted as, read from the active guard via
 * the `CurrentUser` contract. The application layout renders the amber
 * banner whenever that variable is present.
 *
 * The share happens before the request is handled so the variable is
 * already bound when the page view renders downstream.
 *
 * The middleware reads the session through the injected `Session`
 * contract; it is the one Internal middleware sanctioned to do so and
 * therefore sits on the `noAuthFacadeOrHelper` allow-list.
 */
final readonly class ImpersonationBannerMiddleware
{
    private const SESSION_ORIGINAL_USER_ID = 'auth.impersonating.original_user_id';

    public function __construct(
        private Session $session,
        private ViewFactory $views,
        private CurrentUser $currentUser,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->session->get(self::SESSION_ORIGINAL_USER_ID) !== null && $this->currentUser->isAuthenticated()) {
            $this->views->share('impersonatingPartnerUsername', $this->currentUser->user()->username);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
