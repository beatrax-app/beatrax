<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;

/**
 * Single permissible sign-out path.
 *
 * Locks the app-lock state first (so the key custodian releases any at-rest
 * keychain entry holding the data key — see KeyCustodian::forget), then logs
 * the user out of the active guard, invalidates the session, and regenerates
 * the CSRF token so a stale session identifier cannot be replayed.
 *
 * The lock() call must precede logout(): `session->invalidate()` drops the
 * `beatrax_data_key` handle from the Laravel session but does NOT reach the
 * custodian, so on the mobile bundle the raw key would otherwise outlive the
 * session inside the iOS Keychain / Android Keystore. On web / desktop this
 * lock() is a cheap no-op-then-invalidate (forget() is a no-op there).
 */
final class LogoutAction
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Session $session,
        private readonly LockStateManager $lockState,
    ) {}

    public function __invoke(): void
    {
        // Release the custodied data key (forgets the mobile keychain entry)
        // while the session still carries the handle.
        $this->lockState->lock($this->session);

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
