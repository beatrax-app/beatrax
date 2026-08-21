<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;

final class LogoutAction
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly SessionFactory $session,
        private readonly LockStateManager $lockState,
    ) {}

    public function __invoke(): void
    {
        // Before invalidate(), which drops the handle without reaching the
        // custodian, leaving the raw key in the mobile Keychain.
        $this->lockState->lock(($this->session)());

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();

        // Read before logout(): the guard resolves no user afterwards, so the
        // locale would come back null and the login page lose the language.
        $locale = $this->currentLocale($guard);

        $guard->logout();

        ($this->session)()->invalidate();
        ($this->session)()->regenerateToken();

        // After invalidate(), which flushes everything: the locale has to land
        // in the fresh session to survive.
        if ($locale !== null) {
            ($this->session)()->put('locale', $locale);
        }
    }

    private function currentLocale(StatefulGuard $guard): ?string
    {
        $user = $guard->user();

        // A null locale is the stored value for "auto", so carrying nothing
        // forward is right — negotiation resumes from the browser.
        $locale = $user instanceof User ? $user->locale : null;

        return is_string($locale) && $locale !== '' ? $locale : null;
    }
}
