<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Public\Contracts\Clock;

final class AppLockProvisioner
{
    // A PIN's only entropy is its length, so a short one is offline-brute-
    // forceable from a stolen database. Enforced here, below every UI-layer
    // validator, so no caller can provision a data key beneath the floor.
    private const MIN_PIN_LENGTH = 6;

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

    private function assertPinMeetsFloor(string $pin): void
    {
        if (mb_strlen($pin) < self::MIN_PIN_LENGTH) {
            throw ValidationException::withMessages([
                'pin' => ['The app lock PIN must be at least '.self::MIN_PIN_LENGTH.' digits.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function enable(int $userId, string $pin, string $accountPassword, ?Session $session = null): void
    {
        // Defence in depth: a caller-level gap must never mint a key from a
        // weak PIN, because that key wraps the keyring of delivered epochs.
        if ($accountPassword === '') {
            throw ValidationException::withMessages([
                'pin' => ['A PIN and account password are required to enable the app lock.'],
            ]);
        }

        $this->assertPinMeetsFloor($pin);

        // The new data key invalidates every per-device biometric wrap, so a
        // leftover enrollment must not survive to unlock with old material.
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

        // updateOrInsert does not manage timestamps; created_at on insert only.
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
            // Any prior cold-start enclave blob wraps the old key. The flag is
            // reset so the mobile recover path refuses the dead blob.
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

        // The user just proved PIN and password, so the coherent post-enable
        // state is unlocked with the key, not locked without it.
        if ($session !== null) {
            $this->lockState->unlock($session, $dataKey);
        }

        // Last, after both wraps and any session copy are written. The session
        // shares this buffer, so zeroing is best-effort by design.
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
        $this->assertPinMeetsFloor($newPin);

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
        $this->assertPinMeetsFloor($newPin);

        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'pin_hash', 'pin_wrapped_key']);

        $salt = self::stringColumn($row, 'kdf_salt');
        $pinHash = self::stringColumn($row, 'pin_hash');
        $pinWrapped = self::stringColumn($row, 'pin_wrapped_key');

        // A wrong PIN and an unusable blob refuse identically: separating them
        // would confirm a correct PIN against a corrupt account.
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

        // $dataKey is unchanged by a plain PIN change; only its wrap moved.
        $this->events->dispatch(new AppLockPassphraseChanged($userId, $dataKey, $dataKey));

        sodium_memzero($dataKey);

        return true;
    }

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

    // Bypasses the failed-attempt backoff meter on purpose: that is scoped to
    // lock-screen attempts, and callers here are already unlocked.
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

        $this->biometricStore->deleteForUser($userId);

        return true;
    }

    // Zeroes the derived wrap key on every path, including the failing one
    // where the caller has nothing left to zero. Null when the blob will not
    // unwrap: a wrong credential and a corrupt blob answer the same.
    private function unwrapDataKey(string $credential, string $salt, string $wrapped): ?string
    {
        $wrapKey = $this->kdf->deriveWrapKey($credential, $salt);
        $dataKey = $this->keyWrap->unwrap($wrapped, $wrapKey);
        sodium_memzero($wrapKey);

        return $dataKey === false ? null : $dataKey;
    }

    // Absent row and off-schema column mean the same to every caller here.
    private static function stringColumn(?object $row, string $column): ?string
    {
        if ($row === null) {
            return null;
        }

        $value = get_object_vars($row)[$column] ?? null;

        return is_string($value) ? $value : null;
    }
}
