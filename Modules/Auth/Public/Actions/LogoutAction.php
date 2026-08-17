<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
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
        // lock() must precede session->invalidate(): invalidate() drops the
        // session's data-key handle but never reaches the custodian, so on
        // the mobile bundle the raw key would otherwise outlive the session
        // in the iOS Keychain / Android Keystore.
        $this->lockState->lock(($this->session)());

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();

        // Read before logout(), while the user is still resolvable. A guest
        // has no stored preference, so SetLocale negotiates from the session
        // and then Accept-Language — and invalidate() takes the session with
        // it. Someone who set the whole app to Dutch was therefore handed an
        // English login form, with an English language picker, as the gate
        // back into their own data.
        $locale = $this->currentLocale($guard);

        $guard->logout();

        ($this->session)()->invalidate();
        ($this->session)()->regenerateToken();

        // Carried into the fresh session, not the old one: invalidate() flushes
        // everything, so this has to be put back afterwards to survive.
        if ($locale !== null) {
            ($this->session)()->put('locale', $locale);
        }
    }

    private function currentLocale(StatefulGuard $guard): ?string
    {
        $user = $guard->user();

        // null is a real stored value meaning "auto" — carrying nothing then
        // is correct, because negotiation should resume from the browser.
        $locale = $user instanceof User ? $user->locale : null;

        return is_string($locale) && $locale !== '' ? $locale : null;
    }
}
