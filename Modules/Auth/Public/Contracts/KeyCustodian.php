<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

/**
 * Custody boundary for the at-rest data key WHILE the session is unlocked.
 *
 * The Auth lock flow no longer parks the raw data-key bytes directly in the
 * session payload. Instead it hands the raw key to a KeyCustodian, receives
 * an opaque HANDLE, and stores only that handle under
 * `LockStateManager::DATA_KEY_SESSION`. On read it hands the handle back and
 * receives the raw key; on lock it asks the custodian to forget the handle.
 *
 * The handle is deliberately storage-model-agnostic so one call-site wiring
 * serves every platform:
 *
 *   - Web / CI (default `NullKeyCustodian`, Auth module):
 *     the handle IS the raw key. `store`/`read` are identity, `forget` a
 *     no-op. Behaviour is byte-for-byte what it was before this contract
 *     existed — the documented WR-07 accepted-risk session custody.
 *
 *   - Desktop bundle (`DesktopKeyCustodian`, Desktop module):
 *     the handle is the self-contained Electron `safeStorage` ciphertext of
 *     the key (OS keychain / DPAPI / Keychain Services). It carries no
 *     external state, so `forget` is a no-op.
 *
 *   - Mobile bundle (`SecureStorageKeyCustodian`, Mobile module):
 *     the raw key is written to the iOS Keychain / Android Keystore via the
 *     `nativephp/mobile-secure-storage` plugin and the handle is only a
 *     reference token. The key never enters the session payload at all, so
 *     `forget` MUST delete the backing Keychain entry.
 *
 * Wrap / unwrap of the key under the PIN- or password-derived KEK stays
 * entirely in the Auth module (AppLockKdf + AppLockKeyWrap). A custodian
 * never derives, wraps, or unwraps anything — it only protects the already
 * unwrapped key while it is held.
 */
interface KeyCustodian
{
    /**
     * Take custody of the raw data-key bytes and return an opaque handle to
     * be held in the session for the duration of the unlocked window.
     *
     * Implementations that cannot reach their backing store right now (e.g.
     * a mobile custodian resolved off-device, or desktop safeStorage during
     * an early-boot race) MUST degrade gracefully by returning the raw key
     * unchanged — the pass-through session custody then applies, exactly as
     * on web. The environment is stable within a session, so a matching
     * {@see self::read()} on the same host reverses the operation correctly.
     */
    public function store(string $rawKey): string;

    /**
     * Reverse {@see self::store()}: given the handle held in the session,
     * return the raw data-key bytes, or NULL when the key is unrecoverable.
     *
     * When the backing store is unavailable (the degraded path above), the
     * handle IS the raw key, so it is returned unchanged.
     *
     * Returns null when the custodian OWNS a real backing entry for this
     * handle but cannot recover the key from it — the Keychain entry was
     * evicted / the safeStorage ciphertext no longer decrypts on this
     * machine. Callers MUST treat null as "no key held" (fall back to a PIN
     * unlock) and never as key bytes — returning the opaque handle or the
     * ciphertext as if it were the key would hand a bogus value to the
     * encryption layer.
     */
    public function read(string $handle): ?string;

    /**
     * Release any external state the handle refers to.
     *
     * A no-op for stateless handles (web pass-through, desktop ciphertext);
     * for the mobile custodian it deletes the Keychain/Keystore entry so the
     * key does not outlive the unlocked window. Safe to call with a handle
     * whose backing entry is already gone.
     */
    public function forget(string $handle): void;
}
