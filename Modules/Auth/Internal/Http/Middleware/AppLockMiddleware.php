<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the app-lock gate on every authenticated route.
 *
 * When an authenticated user has a locked session, every request is
 * redirected to the app-lock screen. The lock screen (auth.lock) and the
 * logout route are exempt so the user can enter their PIN or sign out
 * without being trapped in a redirect loop.
 *
 * Unauthenticated requests pass through untouched: guests are handled by
 * the Authenticate middleware that is prepended to the auth group; the lock
 * is an extra layer on top of authentication, not a replacement.
 *
 * The middleware is registered via:
 *   - $router->pushMiddlewareToGroup('auth', AppLockMiddleware::class)
 *     so every web+auth route is gated (D-01 server-authoritative lock).
 *   - Livewire::addPersistentMiddleware(AppLockMiddleware::class)
 *     so /livewire/update requests re-run the gate on every Livewire
 *     component update, preventing bypass via the update endpoint (T-05-06).
 *
 * D-02 (lock replaces session expiry): the global session lifetime in
 * config/session.php is already set to 43 200 minutes (30 days), which
 * satisfies the rolling-window requirement for all users unconditionally.
 * No per-request runtime override is needed; the lock_enabled flag controls
 * whether the lock gate fires, not the lifetime.
 */
final readonly class AppLockMiddleware
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'auth.lock',
        'auth.lock.biometric.challenge',
        'auth.lock.biometric.verify',
        'auth.lock.biometric.enroll',
        'logout',
    ];

    public function __construct(
        private CurrentUser $currentUser,
        private LockStateManager $lockState,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            $routeName = $request->route()?->getName();

            if ($this->lockState->isLocked($request->session())
                && ! in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
                return new RedirectResponse($this->urls->route('auth.lock'));
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
