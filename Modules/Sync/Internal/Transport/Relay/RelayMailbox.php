<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

/**
 * Zero-knowledge ciphertext mailbox for the store-and-forward relay (XPORT-03, D-02).
 *
 * HARD ZK INVARIANT (T-13-06 / T-13-02):
 * This class stores and routes opaque Noise ciphertext blobs addressed by
 * recipient device_id. It MUST NEVER:
 *   - Call any sodium_* function on the blob
 *   - Call json_decode() on the blob
 *   - Read or write any user_id column
 *   - Inspect blob content in any way
 *
 * Authorization (who may drain a mailbox) is enforced by the relay:serve
 * endpoint (Plan 05), NOT inside this storage class. RelayMailbox is a pure
 * ZK store — it knows only routing metadata: recipient_did + delivered_at state.
 *
 * GC policy: delivered blobs expire 7 days after delivery; undelivered blobs
 * expire 30 days after creation. Both are set via the expires_at ISO8601 column.
 * garbageCollect() deletes any row whose expires_at has passed (lexical UTC ISO8601
 * comparison — consistent with the Phase 12/13 expires_at invariant).
 */
final readonly class RelayMailbox
{
    /** Days before an undelivered blob is GC'd. */
    private const UNDELIVERED_TTL_DAYS = 30;

    /** Days before a delivered blob is GC'd after delivery. */
    private const DELIVERED_TTL_DAYS = 7;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * Store an opaque ciphertext blob addressed to $recipientDid.
     *
     * ZK: $blob is stored verbatim — no decryption, no inspection.
     * The relay routing key is $recipientDid (a device_id UUID, not a user_id).
     *
     * @param  string  $senderDid  Sending device_id (routing metadata only)
     * @param  string  $recipientDid  Receiving device_id (mailbox address)
     * @param  string  $blob  Opaque Noise ciphertext — never inspected here
     */
    public function deliver(string $senderDid, string $recipientDid, string $blob): void
    {
        $now = $this->clock->now()->toIso8601ZuluString();
        $expiresAt = $this->clock->now()
            ->addDays(self::UNDELIVERED_TTL_DAYS)
            ->toIso8601ZuluString();

        $this->db->connection()->table('relay_mailbox')->insert([
            'sender_did' => $senderDid,
            'recipient_did' => $recipientDid,
            'blob' => $blob,
            'created_at' => $now,
            'delivered_at' => null,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Return all pending (undelivered) blobs for $recipientDid, ordered by created_at.
     *
     * Uses the partial index relay_mailbox_pending_idx (recipient_did, delivered_at
     * WHERE delivered_at IS NULL) for efficient drain queries.
     *
     * ZK: returns rows as-is — blob is never inspected or decoded by this method.
     *
     * @return list<\stdClass> Pending mailbox rows (id, sender_did, recipient_did, blob, created_at, expires_at)
     */
    public function drain(string $recipientDid): array
    {
        /** @var list<\stdClass> $rows */
        $rows = $this->db->connection()
            ->table('relay_mailbox')
            ->where('recipient_did', $recipientDid)
            ->whereNull('delivered_at')
            ->orderBy('created_at')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * Mark a blob as delivered and reset its TTL to the shorter delivered TTL.
     *
     * Sets delivered_at = now() and resets expires_at to now + 7 days so
     * delivered blobs are GC'd sooner than undelivered ones.
     *
     * @param  int  $id  The relay_mailbox.id of the confirmed row
     */
    public function confirm(int $id): void
    {
        $now = $this->clock->now()->toIso8601ZuluString();
        $newExpiresAt = $this->clock->now()
            ->addDays(self::DELIVERED_TTL_DAYS)
            ->toIso8601ZuluString();

        $this->db->connection()
            ->table('relay_mailbox')
            ->where('id', $id)
            ->update([
                'delivered_at' => $now,
                'expires_at' => $newExpiresAt,
            ]);
    }

    /**
     * Delete rows whose expires_at is in the past.
     *
     * Lexical UTC ISO8601 string comparison is safe because the format is
     * zero-padded and lexicographic order matches chronological order. Consistent
     * with the Phase 12/13 expires_at invariant (commit fad5f41 / pairing_tokens GC).
     *
     * Returns the number of rows deleted.
     */
    public function garbageCollect(): int
    {
        $now = $this->clock->now()->toIso8601ZuluString();

        return $this->db->connection()
            ->table('relay_mailbox')
            ->where('expires_at', '<', $now)
            ->delete();
    }
}
