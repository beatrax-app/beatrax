<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Session\Session;

/**
 * Reads and writes the app-lock state in the user's session.
 *
 * Two session keys are managed:
 *   - SESSION_KEY ('beatrax_locked'): boolean flag; true = locked.
 *   - DATA_KEY_SESSION ('beatrax_data_key'): the held data-key bytes;
 *     stored only while the session is unlocked, forgotten on lock().
 *
 * Default behaviour when SESSION_KEY is absent: isLocked() returns false
 * (an uninitialized session is not locked — the user has not set up a PIN
 * yet, or the app has just been freshly booted with no lock state written).
 *
 * SECURITY NOTE: The data key held in DATA_KEY_SESSION is in-memory only
 * for the duration of the session; lock() clears it immediately. The
 * only persistent copy is the wrapped blob in user_app_lock_configs.
 */
final class LockStateManager
{
    /** Session key that carries the boolean lock flag. */
    public const SESSION_KEY = 'beatrax_locked';

    /** Session key that carries the raw data-key bytes while unlocked. */
    public const DATA_KEY_SESSION = 'beatrax_data_key';

    /**
     * Returns true if the session is currently in a locked state.
     *
     * An absent SESSION_KEY is treated as unlocked (false), which covers
     * both the "no PIN set up" and "fresh session" cases.
     */
    public function isLocked(Session $session): bool
    {
        return (bool) $session->get(self::SESSION_KEY, false);
    }

    /**
     * Transition to the locked state.
     *
     * Sets SESSION_KEY to true and immediately forgets the DATA_KEY_SESSION
     * so the key bytes are no longer reachable in this session.
     */
    public function lock(Session $session): void
    {
        $session->put(self::SESSION_KEY, true);
        $session->forget(self::DATA_KEY_SESSION);
    }

    /**
     * Transition to the unlocked state and store the data key for the
     * duration of the session.
     *
     * Sets SESSION_KEY to false and stores $dataKey under DATA_KEY_SESSION.
     * The caller (typically PinVerificationService) is responsible for
     * sodium_memzero()-ing the local copy after calling this method.
     */
    public function unlock(Session $session, string $dataKey): void
    {
        $session->put(self::SESSION_KEY, false);
        $session->put(self::DATA_KEY_SESSION, $dataKey);
    }
}
