<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;

/**
 * Single permissible sign-out path.
 *
 * Logs the user out of the active guard, then invalidates the session
 * and regenerates the CSRF token so a stale session identifier cannot be
 * replayed after sign-out.
 */
final class LogoutAction
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Session $session,
    ) {}

    public function __invoke(): void
    {
        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
