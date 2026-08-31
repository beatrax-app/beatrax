<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Contracts\Clock;
use stdClass;

final readonly class BiometricDeviceStore
{
    public const int BIOMETRIC_DISABLE_THRESHOLD = 5;

    // The only value the enrolment route writes. The column's other documented
    // value, 'nativephp_macos', was never reachable: its detector took a flag
    // no caller passed, and the OS-gated path enrols through ColdStartVault
    // without touching this table at all.
    public const string PLATFORM_WEBAUTHN = 'webauthn';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * @param  string  $biometricWrapSecret  32-byte random server secret (raw bytes).
     * @param  string|null  $publicKeyCbor  COSE-encoded public key bytes (null for NativePHP path).
     * @param  string  $platform  One of the self::PLATFORM_* values.
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
     * @return Collection<int, stdClass>
     */
    public function findForUser(int $userId): Collection
    {
        return $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->get();
    }

    public function findByCredentialId(int $userId, string $credentialId): ?stdClass
    {
        return $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->where('credential_id', $credentialId)
            ->first();
    }

    public function incrementFailureCount(int $id): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('id', $id)
            ->increment('biometric_failed_count');
    }

    public function resetFailureCount(int $id): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('id', $id)
            ->update(['biometric_failed_count' => 0]);
    }

    public function resetAllForUser(int $userId): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->update(['biometric_failed_count' => 0]);
    }

    // Replay protection: a non-increasing counter suggests a cloned
    // authenticator, and the caller must reject it before reaching here.
    public function updateCounter(int $id, int $counter): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('id', $id)
            ->update(['counter' => $counter]);
    }

    public function deleteForUser(int $userId): void
    {
        $this->db->connection()
            ->table('user_biometric_credentials')
            ->where('user_id', $userId)
            ->delete();
    }

    /**
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
