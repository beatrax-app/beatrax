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
}
