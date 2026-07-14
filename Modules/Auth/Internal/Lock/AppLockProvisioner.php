<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Public\Contracts\Clock;

/**
 * Provisions the app-lock feature for a user: generates the data key,
 * wraps it under both the PIN and the account password, and persists the
 * configuration to user_app_lock_configs.
 *
 * Key-wrapping discipline (D-19, D-21):
 *   - A fresh random data key (SODIUM_CRYPTO_SECRETBOX_KEYBYTES = 32 bytes)
 *     is generated with random_bytes() on every enable() call.
 *   - The same KDF salt is shared for PIN and password derivation within a
 *     single enrollment — the outputs are distinct because the secrets differ.
 *   - Each derived wrap key is zeroed immediately after use (sodium_memzero).
 *   - The data key local variable is zeroed after both wraps are written.
 *     IN-08 caveat: PHP's sodium_memzero only zeroes refcount-1 buffers; when
 *     the key has been handed to $session->put() the buffer is shared and the
 *     bytes intentionally live on in the session — the memzero is best-effort
 *     process-memory hygiene for the local reference, not a guarantee.
 *
 * The upsert uses updateOrInsert() scoped to user_id so enable() is
 * idempotent: re-enabling the lock (e.g. changing the PIN) replaces the
 * old config without leaving orphan rows.
 *
 * D-02 (lock replaces session expiry): the global session lifetime in
 * config/session.php is already 43 200 minutes (30 days). Setting
 * lock_enabled=true is the flag AppLockMiddleware reads; no separate
 * runtime lifetime override is required.
 */
final class AppLockProvisioner
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AppLockKdf $kdf,
        private readonly AppLockKeyWrap $keyWrap,
        private readonly PinHasher $pinHasher,
        private readonly Clock $clock,
        private readonly LockStateManager $lockState,
        private readonly BiometricDeviceStore $biometricStore,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Enable the app lock for $userId: generate and double-wrap the data key.
     *
     * After this call:
     *   - user_app_lock_configs has lock_enabled=true, pin_hash, kdf_salt,
     *     pin_wrapped_key, password_wrapped_key, failed_attempts=0.
     *   - All local copies of the data key and derived wrap keys have been
     *     zeroed with sodium_memzero.
     *   - If $session is provided, the session is transitioned to the UNLOCKED
     *     state with the data key stored (the user just proved their PIN + account
     *     password, so an unlocked session with the key is the coherent post-enable
     *     state — Gap B fix per QA review).
     *
     * @throws ValidationException when $pin or $accountPassword is empty (HIGH-02,
     *                             15-import-join-REVIEW.md). Defense-in-depth: every
     *                             known caller (AppLockSettingsSection::setPin(),
     *                             MobileImportBootstrap::provisionDeviceLocally())
     *                             already validates non-empty input BEFORE calling
     *                             here, but a caller-level validation gap must never
     *                             be able to mint an app-lock KEK derived from an
     *                             EMPTY PIN/password — that KEK goes on to wrap the
     *                             GDK keyring that will hold delivered desktop
     *                             epochs, so a weak/empty-derived key is a real
     *                             security regression, not just a UX bug.
     */
    public function enable(int $userId, string $pin, string $accountPassword, ?Session $session = null): void
    {
        if ($pin === '' || $accountPassword === '') {
            throw ValidationException::withMessages([
                'pin' => ['A PIN and account password are required to enable the app lock.'],
            ]);
        }

        // WR-06: enable() generates a NEW data key, which invalidates every
        // existing per-device biometric wrap (they hold the OLD key). Delete
        // stale credentials so a leftover enrollment from a previous
        // enable/disable cycle can never unlock with divergent key material.
        $this->biometricStore->deleteForUser($userId);

        // Generate a fresh data key.
        $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        // Single salt shared across PIN and password derivations.
        $salt = $this->kdf->generateSalt();

        // Wrap under PIN.
        $pinWrapKey = $this->kdf->deriveWrapKey($pin, $salt);
        $pinWrappedKey = $this->keyWrap->wrap($dataKey, $pinWrapKey);
        sodium_memzero($pinWrapKey);

        // Wrap under account password.
        $pwWrapKey = $this->kdf->deriveWrapKey($accountPassword, $salt);
        $passwordWrappedKey = $this->keyWrap->wrap($dataKey, $pwWrapKey);
        sodium_memzero($pwWrapKey);

        // Hash the PIN for fast verification (sodium_crypto_pwhash_str).
        $pinHash = $this->pinHasher->hash($pin);

        // Upsert the configuration row — idempotent on user_id. The query
        // builder's updateOrInsert does not manage timestamps (IN-07), so
        // they are set explicitly: created_at only when inserting.
        $now = $this->clock->now()->toDateTimeString();
        $exists = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->exists();

        $values = [
            'pin_hash' => $pinHash,
            'kdf_salt' => $salt,
            'pin_wrapped_key' => $pinWrappedKey,
            'password_wrapped_key' => $passwordWrappedKey,
            'lock_enabled' => true,
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_activity_at' => $this->clock->now(),
            'updated_at' => $now,
        ];

        if (! $exists) {
            $values['created_at'] = $now;
        }

        $this->db->connection()->table('user_app_lock_configs')->updateOrInsert(
            ['user_id' => $userId],
            $values,
        );

        // Unlock the session with the live data key so the caller's session is in a
        // coherent state immediately after enable(): lock_enabled=true in the DB,
        // but the session is UNLOCKED (key available). The user just proved their
        // PIN + account password, so this matches the banking-app model (D-19, Gap B fix).
        if ($session !== null) {
            $this->lockState->unlock($session, $dataKey);
        }

        // Zero the local data-key reference last — after both wrap blobs are
        // safely stored and the session copy (if any) has been written. When
        // $session holds the key the buffer is shared (refcount > 1), so this
        // is best-effort only (IN-08): the session copy persists by design.
        sodium_memzero($dataKey);
    }

    /**
     * Returns true when the user has lock_enabled=true in user_app_lock_configs.
     */
    public function isEnabled(int $userId): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['lock_enabled']);

        if ($row === null) {
            return false;
        }

        return (bool) $row->lock_enabled;
    }

    /**
     * Re-wraps the data key under a new PIN using the password recovery wrap (D-11/D-21).
     *
     * The password-derived wrap (password_wrapped_key) is the authoritative recovery
     * path: it survives a forgotten PIN because the account password is always available
     * after re-authentication. After this call the PIN wrap and the PIN hash reflect
     * the new PIN; the password wrap is unchanged (it still decrypts to the same key K).
     *
     * Returns false when:
     *   - No config row exists for the user.
     *   - $accountPassword is wrong (unwrap fails).
     *   - The password_wrapped_key blob is corrupted.
     *
     * Every derived wrap key and the data key are zeroed with sodium_memzero after use.
     */
    public function rewrapForNewPin(int $userId, string $accountPassword, string $newPin): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'password_wrapped_key']);

        if ($row === null) {
            return false;
        }

        if (! is_string($row->kdf_salt) || ! is_string($row->password_wrapped_key)) {
            return false;
        }

        // Unwrap data key using the account-password wrap.
        $pwWrapKey = $this->kdf->deriveWrapKey($accountPassword, $row->kdf_salt);
        $dataKey = $this->keyWrap->unwrap($row->password_wrapped_key, $pwWrapKey);
        sodium_memzero($pwWrapKey);

        if ($dataKey === false) {
            return false;
        }

        // Re-wrap under the new PIN.
        $newPinWrapKey = $this->kdf->deriveWrapKey($newPin, $row->kdf_salt);
        $newPinWrappedKey = $this->keyWrap->wrap($dataKey, $newPinWrapKey);
        sodium_memzero($newPinWrapKey);
        sodium_memzero($dataKey);

        $newPinHash = $this->pinHasher->hash($newPin);

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'pin_hash' => $newPinHash,
                'pin_wrapped_key' => $newPinWrappedKey,
                'failed_attempts' => 0,
                'locked_until' => null,
            ]);

        return true;
    }

    /**
     * Changes the PIN after verifying the current PIN (D-23 security downgrade confirmation).
     *
     * Reads the data key from the current PIN wrap, then writes a new pin_hash and
     * pin_wrapped_key under $newPin. The password_wrapped_key is not touched — the
     * recovery wrap remains valid.
     *
     * Returns false when $currentPin is wrong or the blob is corrupted; the stored
     * wraps are unchanged in that case — nothing is dispatched, no partial state.
     *
     * D-10: dispatches `AppLockPassphraseChanged` right before returning success so
     * a Sync listener re-wraps the GDK (Group Data Key) keyring under the (still
     * current — see the event's own docblock) app-lock data key, guaranteeing the
     * keyring file is never left stale relative to the just-completed passphrase
     * change. Auth has zero compile-time dependency on Sync: this dispatches only
     * Auth's own Public event. The listener is deliberately best-effort/never-throw
     * (mirrors `SyncCaptureListener`'s D-07 discipline) — a GDK re-wrap failure
     * (e.g. an independently corrupted keyring file) must never make this method
     * throw instead of returning its documented `bool`, and must never leave
     * `user_app_lock_configs` half-updated; the PIN change itself has already
     * succeeded by the time this dispatches.
     */
    public function changePin(int $userId, string $currentPin, string $newPin): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'pin_hash', 'pin_wrapped_key']);

        if ($row === null) {
            return false;
        }

        if (! is_string($row->pin_hash) || ! $this->pinHasher->verify($currentPin, $row->pin_hash)) {
            return false;
        }

        if (! is_string($row->kdf_salt) || ! is_string($row->pin_wrapped_key)) {
            return false;
        }

        // Unwrap data key using the current PIN.
        $curWrapKey = $this->kdf->deriveWrapKey($currentPin, $row->kdf_salt);
        $dataKey = $this->keyWrap->unwrap($row->pin_wrapped_key, $curWrapKey);
        sodium_memzero($curWrapKey);

        if ($dataKey === false) {
            return false;
        }

        // Re-wrap under the new PIN.
        $newPinWrapKey = $this->kdf->deriveWrapKey($newPin, $row->kdf_salt);
        $newPinWrappedKey = $this->keyWrap->wrap($dataKey, $newPinWrapKey);
        sodium_memzero($newPinWrapKey);

        $newPinHash = $this->pinHasher->hash($newPin);

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'pin_hash' => $newPinHash,
                'pin_wrapped_key' => $newPinWrappedKey,
            ]);

        // D-10 GDK re-wrap. $dataKey is the app-lock KEK; it is the same
        // value before and after a plain PIN change (only its PIN-derived
        // WRAP just changed above) — see AppLockPassphraseChanged's docblock.
        $this->events->dispatch(new AppLockPassphraseChanged($userId, $dataKey, $dataKey));

        sodium_memzero($dataKey);

        return true;
    }

    /**
     * Establish a coherent app-lock session state right after credential login (WR-03).
     *
     * The LOCK-04 contract forbids the incoherent "unlocked UI, no data key"
     * state: before this hook, a fresh password login produced exactly that
     * (isLocked() defaulted to false with DATA_KEY_SESSION absent), so
     * AppLockKeyService::release() returned null on a nominally-unlocked
     * session with no surface prompting the PIN to restore the key.
     *
     * At login time the plaintext account password is available, so the data
     * key is recovered through the D-21 password recovery wrap and the session
     * is unlocked WITH the key — password re-auth is a strictly stronger gate
     * than the PIN, so skipping the PIN screen here is sound (banking-app
     * model, D-02). If the password wrap cannot be unwrapped (e.g. stale wrap
     * after an account-password change), the session starts LOCKED instead and
     * the PIN/biometric restores the key via the lock screen — never the
     * incoherent unlocked/key-less state.
     *
     * No-op when the lock is not enabled for the user.
     */
    public function primeSessionAfterLogin(int $userId, string $accountPassword, Session $session): void
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['lock_enabled', 'kdf_salt', 'password_wrapped_key']);

        if ($row === null || ! (bool) $row->lock_enabled) {
            return;
        }

        if (! is_string($row->kdf_salt) || ! is_string($row->password_wrapped_key)) {
            $this->lockState->lock($session);

            return;
        }

        $pwWrapKey = $this->kdf->deriveWrapKey($accountPassword, $row->kdf_salt);
        $dataKey = $this->keyWrap->unwrap($row->password_wrapped_key, $pwWrapKey);
        sodium_memzero($pwWrapKey);

        if ($dataKey === false) {
            // Fail closed: corrupted/stale wrap → start locked; the PIN wrap
            // is still intact and unlocks via the lock screen.
            $this->lockState->lock($session);

            return;
        }

        $this->lockState->unlock($session, $dataKey);
        sodium_memzero($dataKey);
    }

    /**
     * Verify a PIN against the stored hash WITHOUT any side effects.
     *
     * Used for D-23 confirmations that must not mutate lock state — e.g.
     * biometric de-enrollment, which only deletes credential rows and must
     * never touch pin_hash / kdf_salt / wrapped keys (CR-01 fix).
     *
     * Note: this check deliberately bypasses the failed-attempt backoff meter
     * (D-10 scopes that meter to lock-screen unlock attempts); callers sit on
     * an already-unlocked, authenticated settings surface.
     *
     * Returns false when no config row exists, no PIN is set, or the PIN
     * does not match.
     */
    public function verifyPin(int $userId, string $pin): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['pin_hash']);

        if ($row === null || ! is_string($row->pin_hash)) {
            return false;
        }

        return $this->pinHasher->verify($pin, $row->pin_hash);
    }

    /**
     * Disables the app lock after verifying the current PIN (D-23 security downgrade confirmation).
     *
     * Clears all lock-related fields (pin_hash, kdf_salt, pin_wrapped_key,
     * password_wrapped_key, failed_attempts, locked_until), sets
     * lock_enabled=false, AND deletes all biometric credentials (WR-06): the
     * per-device wraps hold the data key disable() just destroyed, so a
     * surviving credential would unlock a future re-enabled lock with stale
     * key material.
     *
     * Returns false when $pin is wrong — the lock stays enabled in that case.
     */
    public function disable(int $userId, string $pin): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['pin_hash']);

        if ($row === null) {
            return false;
        }

        if (! is_string($row->pin_hash) || ! $this->pinHasher->verify($pin, $row->pin_hash)) {
            return false;
        }

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'lock_enabled' => false,
                'pin_hash' => null,
                'kdf_salt' => null,
                'pin_wrapped_key' => null,
                'password_wrapped_key' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
            ]);

        // WR-06: the wrapped data key is gone — remove every biometric
        // credential that wrapped it.
        $this->biometricStore->deleteForUser($userId);

        return true;
    }
}
