<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Contracts\KeyCustodian;

/**
 * Reads and writes the app-lock state in the user's session.
 *
 * Two session keys are managed:
 *   - SESSION_KEY ('beatrax_locked'): boolean flag; true = locked.
 *   - DATA_KEY_SESSION ('beatrax_data_key'): the CUSTODY HANDLE for the
 *     data key (see KeyCustodian), stored only while the session is
 *     unlocked, forgotten on lock(). On the default (web) custodian the
 *     handle IS the raw key, so behaviour is unchanged; on the desktop /
 *     mobile bundles it is a safeStorage ciphertext or a Keychain reference
 *     and the raw key never touches the session payload.
 *
 * Default behaviour when SESSION_KEY is absent: isLocked() returns false
 * (an uninitialized session is not locked — the user has not set up a PIN
 * yet, or the app has just been freshly booted with no lock state written).
 *
 * SECURITY NOTE — key custody while unlocked (documented decision, WR-07):
 * the data key stored under DATA_KEY_SESSION is part of the Laravel session
 * payload, which the session driver serialises AT REST (with the file
 * driver: storage/framework/sessions/*, owner-only permissions) for as long
 * as the session stays unlocked. It is NOT "in-memory only". This is an
 * accepted risk for a local-only, single-machine deployment: the session
 * store and the SQLite database share the same disk and OS user, so an
 * attacker who can read the session files can also read the DB. lock()
 * removes the key from the session immediately; the only deliberate
 * persistent copies are the wrapped blobs in user_app_lock_configs /
 * user_biometric_credentials. Hardening path: D-20 desktop custody
 * (Electron safeStorage via DesktopKeyCustodian) is built but deliberately
 * NOT wired this phase (see WR-08 / Phase 14); wiring it would keep the
 * at-rest session copy ciphertext on the desktop build.
 */
final class LockStateManager
{
    /** Session key that carries the boolean lock flag. */
    public const SESSION_KEY = 'beatrax_locked';

    /** Session key that carries the data-key custody handle while unlocked. */
    public const DATA_KEY_SESSION = 'beatrax_data_key';

    /**
     * @param  KeyCustodian  $custodian  Custody for the held key. Defaults to
     *                                   the pass-through NullKeyCustodian so `new LockStateManager` (used
     *                                   widely in tests and by encryption-enabling test helpers) keeps its
     *                                   pre-custody behaviour. When resolved from the container the bound
     *                                   custodian (Null on web, Desktop / Mobile on their bundles) is
     *                                   injected instead of this default.
     */
    public function __construct(
        private readonly KeyCustodian $custodian = new NullKeyCustodian,
    ) {}

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
     * Sets SESSION_KEY to true, asks the custodian to release any external
     * state the held handle refers to (a no-op on web/desktop; a Keychain
     * delete on mobile), then forgets the handle so it is no longer
     * reachable in this session.
     */
    public function lock(Session $session): void
    {
        $handle = $session->get(self::DATA_KEY_SESSION);
        if (is_string($handle)) {
            $this->custodian->forget($handle);
        }

        $session->put(self::SESSION_KEY, true);
        $session->forget(self::DATA_KEY_SESSION);
    }

    /**
     * Transition to the unlocked state and take custody of the data key for
     * the duration of the session.
     *
     * Sets SESSION_KEY to false and stores the custodian's HANDLE for
     * $dataKey under DATA_KEY_SESSION (on the default custodian the handle
     * is the raw key itself). The caller (typically PinVerificationService)
     * is responsible for sodium_memzero()-ing the local copy after calling
     * this method.
     */
    public function unlock(Session $session, string $dataKey): void
    {
        $session->put(self::SESSION_KEY, false);
        $session->put(self::DATA_KEY_SESSION, $this->custodian->store($dataKey));
    }

    /**
     * Return the raw data key held for this session, or null when no handle
     * is present (never unlocked, or already locked).
     *
     * This is the single reader every consumer of the held key must go
     * through so the custodian's {@see KeyCustodian::read()} is always
     * applied — reading DATA_KEY_SESSION directly would yield the opaque
     * handle, not the key, on the desktop / mobile bundles.
     */
    public function heldKey(Session $session): ?string
    {
        $handle = $session->get(self::DATA_KEY_SESSION);

        return is_string($handle) ? $this->custodian->read($handle) : null;
    }
}
