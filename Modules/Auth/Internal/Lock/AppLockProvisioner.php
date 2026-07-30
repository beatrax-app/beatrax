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
 * @link ../../../../.docs/features/auth/architecture.md
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
     * @throws ValidationException
     */
    public function enable(int $userId, string $pin, string $accountPassword, ?Session $session = null): void
    {
        // Defense-in-depth: every known caller already validates non-empty
        // input before calling here, but a caller-level gap must never mint
        // an app-lock key from an empty PIN/password -- that key goes on to
        // wrap the keyring holding delivered device epochs.
        if ($pin === '' || $accountPassword === '') {
            throw ValidationException::withMessages([
                'pin' => ['A PIN and account password are required to enable the app lock.'],
            ]);
        }

        // Generates a NEW data key, which invalidates every existing
        // per-device biometric wrap (they hold the OLD key). Delete stale
        // credentials so a leftover enrollment from a previous
        // enable/disable cycle can never unlock with divergent key material.
        $this->biometricStore->deleteForUser($userId);

        $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $salt = $this->kdf->generateSalt();

        $pinWrapKey = $this->kdf->deriveWrapKey($pin, $salt);
        $pinWrappedKey = $this->keyWrap->wrap($dataKey, $pinWrapKey);
        sodium_memzero($pinWrapKey);

        $pwWrapKey = $this->kdf->deriveWrapKey($accountPassword, $salt);
        $passwordWrappedKey = $this->keyWrap->wrap($dataKey, $pwWrapKey);
        sodium_memzero($pwWrapKey);

        $pinHash = $this->pinHasher->hash($pin);

        // updateOrInsert does not manage timestamps, so they are set
        // explicitly: created_at only when inserting.
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
            // enable() mints a NEW data key, so any prior cold-start enclave
            // blob (which wraps the OLD key) is stale. Reset the flag so the
            // mobile recover path refuses it (the dead blob is overwritten
            // on the next enroll).
            'cold_start_biometric_enrolled' => false,
            'updated_at' => $now,
        ];

        if (! $exists) {
            $values['created_at'] = $now;
        }

        $this->db->connection()->table('user_app_lock_configs')->updateOrInsert(
            ['user_id' => $userId],
            $values,
        );

        // The user just proved their PIN + account password, so an unlocked
        // session with the key is the coherent post-enable state -- not a
        // locked session with no key.
        if ($session !== null) {
            $this->lockState->unlock($session, $dataKey);
        }

        // Zeroed last, after both wrap blobs and the session copy (if any)
        // are written. When $session holds the key the buffer is shared, so
        // this is best-effort only -- the session copy persists by design.
        sodium_memzero($dataKey);
    }

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

    public function rewrapForNewPin(int $userId, string $accountPassword, string $newPin): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'password_wrapped_key']);

        $salt = self::stringColumn($row, 'kdf_salt');
        $passwordWrapped = self::stringColumn($row, 'password_wrapped_key');

        // No row and a row missing the columns the schema promises are one
        // answer: there is no key material here to re-wrap.
        if ($salt === null || $passwordWrapped === null) {
            return false;
        }

        $dataKey = $this->unwrapDataKey($accountPassword, $salt, $passwordWrapped);
        if ($dataKey === null) {
            return false;
        }

        $newPinWrapKey = $this->kdf->deriveWrapKey($newPin, $salt);
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

    public function changePin(int $userId, string $currentPin, string $newPin): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'pin_hash', 'pin_wrapped_key']);

        $salt = self::stringColumn($row, 'kdf_salt');
        $pinHash = self::stringColumn($row, 'pin_hash');
        $pinWrapped = self::stringColumn($row, 'pin_wrapped_key');

        // A wrong PIN and unusable stored key material refuse together and
        // look the same from outside. Telling them apart would say whether
        // the PIN was right about an account whose blob happens to be
        // corrupt, which is more than a caller needs to know.
        if ($salt === null || $pinHash === null || $pinWrapped === null
            || ! $this->pinHasher->verify($currentPin, $pinHash)
        ) {
            return false;
        }

        $dataKey = $this->unwrapDataKey($currentPin, $salt, $pinWrapped);
        if ($dataKey === null) {
            return false;
        }

        $newPinWrapKey = $this->kdf->deriveWrapKey($newPin, $salt);
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

        // $dataKey is the same value before and after a plain PIN change
        // (only its PIN-derived wrap changed above) -- see
        // AppLockPassphraseChanged's own docblock for the two-argument shape.
        $this->events->dispatch(new AppLockPassphraseChanged($userId, $dataKey, $dataKey));

        sodium_memzero($dataKey);

        return true;
    }

    /**
     * @link ../../../../.docs/features/auth/architecture.md
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

    // Side-effect-free: deliberately bypasses the failed-attempt backoff
    // meter, which is scoped to lock-screen unlock attempts. Callers sit on
    // an already-unlocked, authenticated settings surface.
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
                // Disabling the lock removes the data key entirely, so any
                // cold-start enclave blob is dead — reset the flag.
                'cold_start_biometric_enrolled' => false,
            ]);

        // The wrapped data key is gone -- remove every biometric credential
        // that wrapped it.
        $this->biometricStore->deleteForUser($userId);

        return true;
    }

    // Derives the wrap key, unwraps with it and zeroes it on every path,
    // including the failing one where the caller has nothing left to zero.
    // Null when the blob will not unwrap — a wrong credential and a corrupt
    // blob are deliberately the same answer.
    private function unwrapDataKey(string $credential, string $salt, string $wrapped): ?string
    {
        $wrapKey = $this->kdf->deriveWrapKey($credential, $salt);
        $dataKey = $this->keyWrap->unwrap($wrapped, $wrapKey);
        sodium_memzero($wrapKey);

        return $dataKey === false ? null : $dataKey;
    }

    // Null when the row is absent or the column does not hold the string the
    // schema promises. Both mean the same thing to every caller here, so
    // they are asked once rather than guarded separately.
    private static function stringColumn(?object $row, string $column): ?string
    {
        if ($row === null) {
            return null;
        }

        $value = get_object_vars($row)[$column] ?? null;

        return is_string($value) ? $value : null;
    }
}
