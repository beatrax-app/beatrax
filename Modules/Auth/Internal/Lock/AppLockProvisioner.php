<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Database\DatabaseManager;
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
 *   - The data key itself is zeroed after both wraps are written.
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
    ) {}

    /**
     * Enable the app lock for $userId: generate and double-wrap the data key.
     *
     * After this call:
     *   - user_app_lock_configs has lock_enabled=true, pin_hash, kdf_salt,
     *     pin_wrapped_key, password_wrapped_key, failed_attempts=0.
     *   - All local copies of the data key and derived wrap keys have been
     *     zeroed with sodium_memzero.
     */
    public function enable(int $userId, string $pin, string $accountPassword): void
    {
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

        // Upsert the configuration row — idempotent on user_id.
        $this->db->connection()->table('user_app_lock_configs')->updateOrInsert(
            ['user_id' => $userId],
            [
                'pin_hash' => $pinHash,
                'kdf_salt' => $salt,
                'pin_wrapped_key' => $pinWrappedKey,
                'password_wrapped_key' => $passwordWrappedKey,
                'lock_enabled' => true,
                'failed_attempts' => 0,
                'locked_until' => null,
                'last_activity_at' => $this->clock->now(),
            ],
        );

        // Zero the data key last — after both wrap blobs are safely stored.
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
     * wraps are unchanged in that case.
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
        sodium_memzero($dataKey);

        $newPinHash = $this->pinHasher->hash($newPin);

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'pin_hash' => $newPinHash,
                'pin_wrapped_key' => $newPinWrappedKey,
            ]);

        return true;
    }

    /**
     * Disables the app lock after verifying the current PIN (D-23 security downgrade confirmation).
     *
     * Clears all lock-related fields (pin_hash, kdf_salt, pin_wrapped_key,
     * password_wrapped_key, failed_attempts, locked_until) and sets lock_enabled=false.
     *
     * Returns false when $pin is wrong — the lock stays enabled in that case.
     *
     * Note: biometric credentials (user_biometric_credentials rows) are cleared by
     * plan 05-05's disable path; this method only clears PIN material from
     * user_app_lock_configs.
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

        return true;
    }
}
