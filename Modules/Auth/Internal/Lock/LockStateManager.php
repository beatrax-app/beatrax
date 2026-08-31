<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Events\AppLockUnlocked;

final readonly class LockStateManager
{
    public const string SESSION_KEY = 'beatrax_locked';

    public const string DATA_KEY_SESSION = 'beatrax_data_key';

    // An unlock is activity, but only the PIN path stamped the config row to
    // say so, so a biometric unlock re-locked on the very next request.
    public const string SESSION_UNLOCK_ACTIVITY_PENDING = 'beatrax_unlock_activity_pending';

    /**
     * @param  KeyCustodian  $custodian  Defaults to the pass-through
     *                                   NullKeyCustodian so `new LockStateManager` (used widely in tests)
     *                                   keeps its pre-custody behaviour; the container binds the
     *                                   platform-appropriate custodian for production resolution.
     * @param  Dispatcher|null  $events  Nullable for the same reason, and only for it: a
     *                                   container-resolved instance always receives one.
     */
    public function __construct(
        private KeyCustodian $custodian = new NullKeyCustodian,
        private ?Dispatcher $events = null,
    ) {}

    public function isLocked(Session $session): bool
    {
        return (bool) $session->get(self::SESSION_KEY, false);
    }

    public function lock(Session $session): void
    {
        $this->releaseHandle($session);

        $session->put(self::SESSION_KEY, true);

        // The pending record belongs to the unlock just undone; carrying it
        // past a lock credits the next session with presence nobody proved.
        $session->forget(self::SESSION_UNLOCK_ACTIVITY_PENDING);
    }

    // Releases a lock flag no PIN or biometric could ever clear, through the
    // same release as lock(): dropping the handle alone would strand the key
    // it names in the Keychain with nothing left pointing at it.
    public function clearStaleLock(Session $session): void
    {
        $this->releaseHandle($session);

        $session->put(self::SESSION_KEY, false);
    }

    // The custodian first: a no-op on web/desktop, a Keychain delete on
    // mobile, and it needs the handle the session is about to forget.
    private function releaseHandle(Session $session): void
    {
        $handle = $session->get(self::DATA_KEY_SESSION);
        if (is_string($handle)) {
            $this->custodian->forget($handle);
        }

        $session->forget(self::DATA_KEY_SESSION);
    }

    // The session holds the key from here on, but the caller's own copy is
    // still live: sodium_memzero() on it remains the caller's responsibility.
    public function unlock(Session $session, string $dataKey): void
    {
        $session->put(self::SESSION_KEY, false);
        $session->put(self::DATA_KEY_SESSION, $this->custodian->store($dataKey));

        // Flagged here, not at the five call sites, because only one of them
        // remembered to and a sixth path would forget too.
        $session->put(self::SESSION_UNLOCK_ACTIVITY_PENDING, true);

        // Announced from the funnel for that same reason: a PIN unlock, a
        // biometric unlock, a sign-in, an enable and an enclave recovery all
        // arrive here, and an event that fired from only one of them would be
        // trusted for all five.
        $this->events?->dispatch(new AppLockUnlocked($session));
    }

    // The only sanctioned reader: it applies the custodian's read(), where
    // DATA_KEY_SESSION holds an opaque handle rather than the key itself on
    // the desktop and mobile bundles.
    public function heldKey(Session $session): ?string
    {
        $handle = $session->get(self::DATA_KEY_SESSION);

        return is_string($handle) ? $this->custodian->read($handle) : null;
    }
}
