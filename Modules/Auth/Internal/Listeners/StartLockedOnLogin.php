<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Listeners;

use Illuminate\Auth\Events\Login;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;

// A remember-me recaller authenticates into a brand-new session, where the
// lock flag is simply absent and the idle window has not expired — so the lock
// stopped applying, with no attacker needed once the 30-day session cookie
// expires under a five-year remember cookie.
/**
 * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
final readonly class StartLockedOnLogin
{
    public function __construct(
        private AppLockProvisioner $provisioner,
        private LockStateManager $lockState,
        private SessionFactory $session,
    ) {}

    // Every login, not only the recaller: the password paths unwrap the data
    // key and unlock immediately afterwards, so locking first is what makes
    // "authenticated but holding no key" impossible rather than unlikely.
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $this->provisioner->isEnabled($user->id)) {
            return;
        }

        $this->lockState->lock(($this->session)());
    }
}
