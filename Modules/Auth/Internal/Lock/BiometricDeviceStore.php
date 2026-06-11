<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Contracts\Clock;
use stdClass;

/**
 * CRUD store for the user_biometric_credentials table.
 *
 * Implements D-16 arm/disarm: after N consecutive biometric failures the
 * credential is disarmed (isArmed returns false) until the next successful
 * PIN unlock re-arms it via resetAllForUser().
 *
 * All queries are scoped to user_id — cross-user access is structurally
 * impossible from within this class (T-05-22).
 *
 * Follows the raw query-builder CRUD discipline from RecoveryCodeAuthenticator:
 * no Eloquent model, direct $db->connection()->table() writes.
 */
final class BiometricDeviceStore
{
    /**
     * Number of consecutive biometric failures after which the button is
     * disabled until the next successful PIN unlock re-arms it (D-16).
     */
    public const BIOMETRIC_DISABLE_THRESHOLD = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Insert a new enrolled biometric credential row for the given user.
     *
     * @param  string  $biometricWrapSecret  32-byte random server secret (raw bytes).
     * @param  string|null  $publicKeyCbor  COSE-encoded public key bytes (null for NativePHP path).
     * @param  string  $platform  'webauthn' or 'nativephp_macos'.
     */
    public function store(
        int $userId,
        string $credentialId,
        string $deviceLabel,
        string $biometricWrapSecret,
        ?string $publicKeyCbor,
        string $platform,
    ): void {
        $now = $this->clock->now()->toDateTimeString();

        $this->db->connection()->table('user_biometric_credentials')->insert([
            'user_id' => $userId,
            'credential_id' => $credentialId,
            'device_label' => $deviceLabel,
            'biometric_wrap_secret' => $biometricWrapSecret,
            'public_key_cbor' => $publicKeyCbor,
            'counter' => 0,
            'platform' => $platform,
            'biometric_failed_count' => 0,
            'enrolled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Return all enrolled credential rows for a user.
     *
     * @return Collection<int, stdClass>
     */
    public function findForUser(int $userId): Collection
    {
        return $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->get();
    }

    /**
     * Find a single credential by user_id + credential_id.
     *
     * Returns null when the credential does not exist or belongs to another user.
     */
    public function findByCredentialId(int $userId, string $credentialId): ?stdClass
    {
        $row = $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->where('credential_id', $credentialId)
            ->first();

        if ($row === null) {
            return null;
        }

        return $row;
    }

    /**
     * Increment the consecutive biometric failure counter for this credential (D-16).
     *
     * When the count reaches BIOMETRIC_DISABLE_THRESHOLD the credential is
     * disarmed: isArmed() returns false until resetFailureCount or resetAllForUser
     * is called.
     */
    public function incrementFailureCount(int $id): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('id', $id)
            ->increment('biometric_failed_count');
    }

    /**
     * Reset the failure counter to 0 for a single credential.
     *
     * Called after a successful biometric assertion (D-16 re-arm).
     */
    public function resetFailureCount(int $id): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('id', $id)
            ->update(['biometric_failed_count' => 0]);
    }

    /**
     * Reset the failure counter for ALL of a user's credentials.
     *
     * Called after a successful PIN unlock (D-16: PIN re-arms all biometrics).
     */
    public function resetAllForUser(int $userId): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->update(['biometric_failed_count' => 0]);
    }

    /**
     * Update the signature counter after a successful WebAuthn assertion.
     *
     * Replay protection: a non-increasing counter means the authenticator may
     * have been cloned. The caller is responsible for rejecting non-increasing
     * counters BEFORE calling this method (T-05-19).
     */
    public function updateCounter(int $id, int $counter): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('id', $id)
            ->update(['counter' => $counter]);
    }

    /**
     * Delete all enrolled credentials for a user.
     *
     * Called when the user disables biometric unlock (cascade on disable).
     */
    public function deleteForUser(int $userId): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * Returns true when the credential is still armed (failure count < threshold).
     *
     * A disarmed credential (biometric_failed_count >= BIOMETRIC_DISABLE_THRESHOLD)
     * must not be allowed to attempt biometric unlock (D-16).
     *
     * @param  stdClass  $credential  A row from user_biometric_credentials.
     */
    public function isArmed(stdClass $credential): bool
    {
        $count = $credential->biometric_failed_count;

        if (! is_int($count) && ! is_string($count)) {
            return false;
        }

        return (int) $count < self::BIOMETRIC_DISABLE_THRESHOLD;
    }
}
