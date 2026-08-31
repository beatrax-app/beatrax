<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Testing;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;

// The sanctioned seam for another module's tests to reach the unlocked state.
// Without it, every suite needing real crypto imported Auth\Internal's
// LockStateManager, which is what the module-boundary rule forbids.
final class AppLockTestHarness
{
    public const string LOCKED_SESSION_KEY = LockStateManager::SESSION_KEY;

    public const string HELD_KEY_SESSION_KEY = LockStateManager::DATA_KEY_SESSION;

    public static function unlock(Session $session, string $dataKey): void
    {
        self::keyService()->admitDataKey($session, $dataKey);
    }

    public static function lock(Session $session): void
    {
        self::keyService()->withhold($session);
    }

    public static function isLocked(Session $session): bool
    {
        return (bool) $session->get(self::LOCKED_SESSION_KEY, false);
    }

    // Built, not resolved: the container binds a platform custodian only in a
    // native bundle, and this must behave the same in every root. The
    // dispatcher is the exception — a harness unlock that announced nothing
    // would be the one unlock listeners never hear.
    private static function keyService(): AppLockKeyService
    {
        $events = Container::getInstance()->make(Dispatcher::class);

        return new AppLockKeyService(new LockStateManager(events: $events));
    }
}
