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
        $now = $this->assertZulu($this->clock->now()->toIso8601ZuluString());
        $expiresAt = $this->assertZulu(
            $this->clock->now()->addDays(self::UNDELIVERED_TTL_DAYS)->toIso8601ZuluString()
        );

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
     * Store a blob only if $recipientDid's pending row count is below $maxPending
     * (resource-exhaustion guard: the relay is open-submission by design, so an
     * unbounded flood of deliveries into one mailbox would otherwise grow the
     * SQLite file without limit for the full 30-day undelivered TTL).
     *
     * The count check and insert run inside one DB transaction so a burst of
     * concurrent deliveries cannot race past the cap between the check and the
     * write.
     *
     * ZK: identical storage semantics to deliver() — $blob is never inspected.
     *
     * @return bool true if the blob was stored, false if the quota was full (nothing written)
     */
    public function deliverIfUnderQuota(string $senderDid, string $recipientDid, string $blob, int $maxPending): bool
    {
        return $this->db->connection()->transaction(function () use ($senderDid, $recipientDid, $blob, $maxPending): bool {
            $pending = $this->db->connection()
                ->table('relay_mailbox')
                ->where('recipient_did', $recipientDid)
                ->whereNull('delivered_at')
                ->count();

            if ($pending >= $maxPending) {
                return false;
            }

            $this->deliver($senderDid, $recipientDid, $blob);

            return true;
        });
    }

    /**
     * Return up to $limit pending (undelivered) blobs for $recipientDid, ordered
     * by created_at (oldest first).
     *
     * Bounded (resource-exhaustion guard): an unbounded drain would let the
     * draining device request the server's entire pending backlog for a mailbox
     * in one response, risking memory exhaustion on the client. Callers that
     * need the full backlog must loop: drain a page, confirm() each returned
     * row (which clears delivered_at so it drops out of the next drain), then
     * drain again — repeating until fewer than $limit rows come back.
     *
     * Uses the partial index relay_mailbox_pending_idx (recipient_did, delivered_at
     * WHERE delivered_at IS NULL) for efficient drain queries.
     *
     * ZK: returns rows as-is — blob is never inspected or decoded by this method.
     *
     * @return list<\stdClass> Pending mailbox rows (id, sender_did, recipient_did, blob, created_at, expires_at)
     */
    public function drain(string $recipientDid, int $limit): array
    {
        /** @var list<\stdClass> $rows */
        $rows = $this->db->connection()
            ->table('relay_mailbox')
            ->where('recipient_did', $recipientDid)
            ->whereNull('delivered_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all();

        return $rows;
    }

    /**
     * Return the recipient_did (routing metadata) for a mailbox row, or null
     * when the row does not exist.
     *
     * Used by the relay endpoint to bind a confirm() authorization to the
     * mailbox owner (CR-04). ZK: reads only the recipient_did routing column —
     * never the blob, never a user_id.
     *
     * @param  int  $id  The relay_mailbox.id to look up.
     */
    public function recipientDidFor(int $id): ?string
    {
        $value = $this->db->connection()
            ->table('relay_mailbox')
            ->where('id', $id)
            ->value('recipient_did');

        return is_string($value) ? $value : null;
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
        $now = $this->assertZulu($this->clock->now()->toIso8601ZuluString());
        $newExpiresAt = $this->assertZulu(
            $this->clock->now()->addDays(self::DELIVERED_TTL_DAYS)->toIso8601ZuluString()
        );

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
        $now = $this->assertZulu($this->clock->now()->toIso8601ZuluString());

        return $this->db->connection()
            ->table('relay_mailbox')
            ->where('expires_at', '<', $now)
            ->delete();
    }

    /**
     * Assert a timestamp is in zero-padded UTC Zulu ISO8601 form (WR-03).
     *
     * The GC compares expires_at as a lexical string, which is only correct when
     * every timestamp shares the same zero-padded `YYYY-MM-DDTHH:MM:SSZ` Zulu
     * format. A timestamp written in an offset form (e.g. `+02:00`) would sort
     * incorrectly and either GC live blobs early (data loss) or never (unbounded
     * growth). This guard makes that invariant explicit at every write site, so a
     * future refactor that drops toIso8601ZuluString() fails loudly instead of
     * silently corrupting GC ordering.
     *
     * @throws \LogicException when $ts is not in Zulu ISO8601 form.
     */
    private function assertZulu(string $ts): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $ts) !== 1) {
            throw new \LogicException(
                "RelayMailbox: timestamp '{$ts}' is not zero-padded UTC Zulu ISO8601. "
                .'Lexical expires_at GC comparison requires the Zulu form (WR-03).'
            );
        }

        return $ts;
    }
}
