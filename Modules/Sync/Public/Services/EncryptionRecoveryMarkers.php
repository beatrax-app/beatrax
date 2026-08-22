<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use stdClass;

// `sync_encryption_state` is this module's table and every other marker on it
// is written from inside Sync. The recovery pass lives in Core because the
// sweep it drives does, so the two stamps it needs come through here rather
// than through a raw cross-module write.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee
 */
final readonly class EncryptionRecoveryMarkers
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // Null for a device that never enabled encryption, which is the one state
    // in which there is nothing to recover and nothing to refuse.
    public function isEnrolled(int $userId): bool
    {
        $state = $this->load($userId);

        return $state !== null && ($state->current_epoch ?? null) !== null;
    }

    public function resealedColumnsDigest(int $userId): ?string
    {
        $value = $this->load($userId)->resealed_columns_digest ?? null;

        return is_string($value) ? $value : null;
    }

    public function historyReprojectedAt(int $userId): ?string
    {
        $value = $this->load($userId)->history_reprojected_at ?? null;

        return is_string($value) ? $value : null;
    }

    public function reprojectedKeyringFingerprint(int $userId): ?string
    {
        $value = $this->load($userId)->reprojected_keyring_fingerprint ?? null;

        return is_string($value) ? $value : null;
    }

    // Both stamped together, and stamped whenever a pass RAN — not only when
    // it replayed something. A pass that looked and found only entries it has
    // no key for has still answered the question for this keyring, and leaving
    // the marks behind would make it ask again on every request.
    /**
     * @param  string|null  $fingerprint  The keyring the pass evaluated against.
     */
    public function markHistoryReprojected(int $userId, ?string $fingerprint): void
    {
        $this->stamp($userId, [
            // Compared against `op_log_quarantine.created_at`, which the
            // replayer writes in this same format.
            'history_reprojected_at' => $this->clock->now()->toDateTimeString(),
            'reprojected_keyring_fingerprint' => $fingerprint,
        ]);
    }

    public function markColumnsResealed(int $userId, string $digest): void
    {
        $this->stamp($userId, ['resealed_columns_digest' => $digest]);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function stamp(int $userId, array $values): void
    {
        $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update([...$values, 'updated_at' => $this->clock->now()]);
    }

    private function load(int $userId): ?stdClass
    {
        /** @var stdClass|null $row */
        $row = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->first();

        return $row;
    }
}
