<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;

final class LogoutAction
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Session $session,
        private readonly LockStateManager $lockState,
    ) {}

    public function __invoke(): void
    {
        // lock() must precede session->invalidate(): invalidate() drops the
        // session's data-key handle but never reaches the custodian, so on
        // the mobile bundle the raw key would otherwise outlive the session
        // in the iOS Keychain / Android Keystore.
        $this->lockState->lock($this->session);

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
