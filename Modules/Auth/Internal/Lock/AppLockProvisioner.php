<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SystemAlertWriter;

/**
 * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
final class AppLockProvisioner
{
    // A PIN's only entropy is its length, so a short one is offline-brute-
    // forceable from a stolen database. Enforced here, below every UI-layer
    // validator, so no caller can provision a data key beneath the floor.
    private const MIN_PIN_LENGTH = 6;

    private const STRANDED_ALERT_KIND = 'auth.lock.key_material_stranded';

    private const STALE_RECOVERY_ALERT_KIND = 'auth.lock.recovery_wrap_stale';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AppLockKdf $kdf,
        private readonly AppLockKeyWrap $keyWrap,
        private readonly PinHasher $pinHasher,
        private readonly Clock $clock,
        private readonly LockStateManager $lockState,
        private readonly BiometricDeviceStore $biometricStore,
        private readonly Dispatcher $events,
        private readonly EncryptionMigrationService $encryption,
        private readonly SystemAlertWriter $alerts,
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

        // Every GDK epoch key, and through it every encrypted column, is
        // wrapped under this one key. Where such data exists the only key
        // this may proceed with is the one that already opens it.
        $dataKey = $this->encryption->isEnabled($userId)
            ? $this->recoverDataKey($userId, $accountPassword)
            : random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        // A leftover enrollment wraps whatever key the previous provisioning
        // held, so it must not survive one that may have replaced it.
        $this->biometricStore->deleteForUser($userId);

        $salt = $this->kdf->generateSalt();

        $pinWrapKey = $this->kdf->deriveWrapKey($pin, $salt);
        $pinWrappedKey = $this->keyWrap->wrap($dataKey, $pinWrapKey);
        sodium_memzero($pinWrapKey);

        $pwWrapKey = $this->kdf->deriveWrapKey($accountPassword, $salt);
        $passwordWrappedKey = $this->keyWrap->wrap($dataKey, $pwWrapKey);
        sodium_memzero($pwWrapKey);

        $pinHash = $this->pinHasher->hash($pin);

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
            'password_wrap_stale_at' => null,
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

    // The account password is the one credential enable() is handed that can
    // open an existing wrap, which is the reason the setup form collects it.
    /**
     * @throws ValidationException when no wrap here still holds the data key.
     */
    private function recoverDataKey(int $userId, string $accountPassword): string
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'password_wrapped_key']);

        $salt = self::stringColumn($row, 'kdf_salt');
        $passwordWrapped = self::stringColumn($row, 'password_wrapped_key');

        $dataKey = $salt !== null && $passwordWrapped !== null
            ? $this->unwrapDataKey($accountPassword, $salt, $passwordWrapped)
            : null;

        if ($dataKey === null) {
            throw ValidationException::withMessages([
                'pin' => ['The key that opens this account\'s encrypted data is not available here, so a new PIN cannot be set over it.'],
            ]);
        }

        return $dataKey;
    }

    // Names which of the four situations a caller is in, so a screen can tell
    // the user rather than fold them into one silent answer. Locked is absent
    // on purpose: this reads durable wraps, which a locked session still has.
    public function keyState(int $userId): AppLockKeyState
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'pin_wrapped_key', 'password_wrapped_key', 'password_wrap_stale_at']);

        $pinWrapped = self::stringColumn($row, 'pin_wrapped_key');
        $wrapped = $pinWrapped ?? self::stringColumn($row, 'password_wrapped_key');

        if (self::stringColumn($row, 'kdf_salt') !== null && $wrapped !== null) {
            return $pinWrapped !== null && self::recoveryWrapIsStale($row)
                ? AppLockKeyState::RecoveryUnreadable
                : AppLockKeyState::Held;
        }

        return $this->encryption->isEnabled($userId)
            ? AppLockKeyState::Stranded
            : AppLockKeyState::Absent;
    }

    // Re-wraps the recovery blob from the old account password to the new one.
    // The salt is shared with the PIN wrap, so it must NOT be regenerated here.
    /**
     * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
     */
    public function rewrapRecoveryKey(int $userId, string $currentPassword, string $newPassword): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'password_wrapped_key']);

        $salt = self::stringColumn($row, 'kdf_salt');
        $passwordWrapped = self::stringColumn($row, 'password_wrapped_key');

        // No lock configured: there is no recovery wrap to carry over, and
        // stamping one that does not exist would report a fault nobody has.
        if ($salt === null || $passwordWrapped === null) {
            return false;
        }

        $dataKey = $this->unwrapDataKey($currentPassword, $salt, $passwordWrapped);

        if ($dataKey === null) {
            $this->markRecoveryWrapStale($userId);

            return false;
        }

        $wrapKey = $this->kdf->deriveWrapKey($newPassword, $salt);
        $rewrapped = $this->keyWrap->wrap($dataKey, $wrapKey);
        sodium_memzero($wrapKey);
        sodium_memzero($dataKey);

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'password_wrapped_key' => $rewrapped,
                'password_wrap_stale_at' => null,
            ]);

        return true;
    }

    // For the password changes that hold no old password to unwrap with — a
    // recovery-code reset, an owner setting a partner's password. The blob is
    // stamped, never cleared: it still opens under the password it was built
    // from, and clearing would foreclose that.
    public function markRecoveryWrapStale(int $userId): void
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'password_wrapped_key', 'password_wrap_stale_at']);

        if (self::stringColumn($row, 'kdf_salt') === null || self::stringColumn($row, 'password_wrapped_key') === null) {
            return;
        }

        if (self::recoveryWrapIsStale($row)) {
            return;
        }

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['password_wrap_stale_at' => $this->clock->now()->toDateTimeString()]);

        $this->raiseOnce(
            $userId,
            self::STALE_RECOVERY_ALERT_KIND,
            'The account password changed without the app-lock recovery wrap being re-wrapped, so that password no '
                .'longer opens the app lock. The PIN still does. Re-link the account password from the app-lock '
                .'settings while the PIN is still known, or a forgotten PIN leaves nothing behind it.',
        );
    }

    // The repair, and the only moment both credentials exist at once: the PIN
    // produces the data key and the account password re-wraps it.
    public function relinkRecoveryWrap(int $userId, string $pin, string $accountPassword): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['kdf_salt', 'pin_hash', 'pin_wrapped_key']);

        $salt = self::stringColumn($row, 'kdf_salt');
        $pinHash = self::stringColumn($row, 'pin_hash');
        $pinWrapped = self::stringColumn($row, 'pin_wrapped_key');

        if ($salt === null || $pinHash === null || $pinWrapped === null
            || ! $this->pinHasher->verify($pin, $pinHash)
        ) {
            return false;
        }

        $dataKey = $this->unwrapDataKey($pin, $salt, $pinWrapped);
        if ($dataKey === null) {
            return false;
        }

        $wrapKey = $this->kdf->deriveWrapKey($accountPassword, $salt);
        $rewrapped = $this->keyWrap->wrap($dataKey, $wrapKey);
        sodium_memzero($wrapKey);
        sodium_memzero($dataKey);

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'password_wrapped_key' => $rewrapped,
                'password_wrap_stale_at' => null,
            ]);

        return true;
    }

    private static function recoveryWrapIsStale(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        /** @var mixed $value */
        $value = get_object_vars($row)['password_wrap_stale_at'] ?? null;

        return $value !== null;
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

        // A PIN change re-wraps the data key without changing it, so the event
        // deliberately carries the same key as both its old and its new value.
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

        if ($row === null) {
            return;
        }

        if (! (bool) $row->lock_enabled) {
            $this->reportStrandedKeyMaterial($userId);

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
            // Proof, not inference: whatever replaced the password — one of the
            // three known writers or one nobody has written yet — this is the
            // sign-in where it stops opening, so stamp it here as well and the
            // enumeration above stops being the thing correctness rests on.
            $this->markRecoveryWrapStale($userId);

            // Fail closed: corrupted/stale wrap → start locked; the PIN wrap
            // is still intact and unlocks via the lock screen.
            $this->lockState->lock($session);

            return;
        }

        $this->lockState->unlock($session, $dataKey);
        sodium_memzero($dataKey);
    }

    // A sign-in is the moment such an install would otherwise come up blank:
    // nothing will ask for a key, so every encrypted column renders empty and
    // says nothing. Raised once per unacknowledged row, so a daily sign-in
    // does not become a daily alert.
    private function reportStrandedKeyMaterial(int $userId): void
    {
        if ($this->keyState($userId) !== AppLockKeyState::Stranded) {
            return;
        }

        $this->raiseOnce(
            $userId,
            self::STRANDED_ALERT_KIND,
            'At-rest encryption is active for this account but no app-lock wrap still holds the data key, '
                .'so every encrypted note, description and counterparty detail reads as empty. '
                .'Pairing with a device that still holds the key is the only way back.',
        );
    }

    // One open row per kind: both key-material faults persist until somebody
    // acts on them, so a per-sign-in copy would bury the first under repeats.
    private function raiseOnce(int $userId, string $kind, string $message): void
    {
        $alreadyRaised = $this->db->connection()
            ->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->whereNull('acknowledged_at')
            ->exists();

        if ($alreadyRaised) {
            return;
        }

        $this->alerts->raiseForUser(
            userId: $userId,
            kind: $kind,
            severity: SystemAlertSeverity::Critical->value,
            message: $message,
        );
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

    // A row that is not there and a PIN that does not verify answer alike:
    // there is no lock here to take down either way.
    public function disable(int $userId, string $pin): AppLockDisableResult
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['pin_hash']);

        if ($row === null || ! is_string($row->pin_hash) || ! $this->pinHasher->verify($pin, $row->pin_hash)) {
            return AppLockDisableResult::PinIncorrect;
        }

        // The wraps below are the only durable copies of the data key. With
        // encrypted data on disk, clearing them IS the stranding, and no later
        // step can undo it — so it does not happen at all.
        if ($this->encryption->isEnabled($userId)) {
            return AppLockDisableResult::EncryptedDataDependsOnIt;
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
                'password_wrap_stale_at' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
                // Disabling the lock removes the data key entirely, so any
                // cold-start enclave blob is dead — reset the flag.
                'cold_start_biometric_enrolled' => false,
            ]);

        $this->biometricStore->deleteForUser($userId);

        return AppLockDisableResult::Disabled;
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

    private static function stringColumn(?object $row, string $column): ?string
    {
        if ($row === null) {
            return null;
        }

        $value = get_object_vars($row)[$column] ?? null;

        return is_string($value) ? $value : null;
    }
}
