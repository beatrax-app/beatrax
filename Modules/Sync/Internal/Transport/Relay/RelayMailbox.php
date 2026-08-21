<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Clock\ZuluTimestamp;

final readonly class RelayMailbox
{
    // HARD ZK INVARIANT: stores and routes opaque Noise ciphertext blobs by
    // recipient device_id. MUST NEVER call sodium_*/json_decode() on the
    // blob, read/write any user_id column, or inspect blob content.
    // Authorization is enforced by the relay:serve endpoint, NOT here.

    private const UNDELIVERED_TTL_DAYS = 30;

    private const DELIVERED_TTL_DAYS = 7;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * @param  string  $senderDid  Sending device_id (routing metadata only)
     * @param  string  $recipientDid  Receiving device_id (mailbox address)
     * @param  string  $blob  Opaque Noise ciphertext — never inspected here
     */
    public function deliver(string $senderDid, string $recipientDid, string $blob): void
    {
        $now = ZuluTimestamp::stamp($this->clock->now());
        $expiresAt = ZuluTimestamp::stamp($this->clock->now()->addDays(self::UNDELIVERED_TTL_DAYS));

        $this->db->connection()->table('relay_mailbox')->insert([
            'sender_did' => $senderDid,
            'recipient_did' => $recipientDid,
            'blob' => $blob,
            'created_at' => $now,
            'delivered_at' => null,
            'expires_at' => $expiresAt,
        ]);
    }

    // Resource-exhaustion guard: the relay is open-submission by design, so
    // an unbounded flood of deliveries into one mailbox would otherwise grow
    // the SQLite file without limit. The count check and insert run inside
    // one DB transaction so concurrent deliveries can't race past the cap.
    /**
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

    // Bounded (resource-exhaustion guard): an unbounded drain would let the
    // draining device request the server's entire pending backlog in one
    // response. Callers needing the full backlog must loop: drain a page,
    // confirm() each row, drain again, until fewer than $limit rows return.
    /**
     * @return list<\stdClass> Pending mailbox rows (id, sender_did, recipient_did, blob, created_at, expires_at)
     */
    public function drain(string $recipientDid, int $limit): array
    {
        /** @var list<\stdClass> $rows */
        $rows = $this->db->connection()
            ->table('relay_mailbox')
            ->where('recipient_did', $recipientDid)
            ->whereNull('delivered_at')
            // Insert order, not just second order. Epoch wraps are enqueued as
            // a batch inside one transaction, so they share a timestamp, and a
            // tie the database broke its own way decided which epoch the
            // recipient ended up treating as current.
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();

        return $rows;
    }

    // Used by the relay endpoint to bind a confirm() authorization to the
    // mailbox owner. ZK: reads only the recipient_did routing column —
    // never the blob, never a user_id.
    /**
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
     * @param  int  $id  The relay_mailbox.id of the confirmed row
     */
    public function confirm(int $id): void
    {
        $now = ZuluTimestamp::stamp($this->clock->now());
        $newExpiresAt = ZuluTimestamp::stamp($this->clock->now()->addDays(self::DELIVERED_TTL_DAYS));

        $this->db->connection()
            ->table('relay_mailbox')
            ->where('id', $id)
            ->update([
                'delivered_at' => $now,
                'expires_at' => $newExpiresAt,
            ]);
    }

    // Lexical UTC ISO8601 string comparison is safe because the format is
    // zero-padded and lexicographic order matches chronological order.
    public function garbageCollect(): int
    {
        $now = ZuluTimestamp::stamp($this->clock->now());

        return $this->db->connection()
            ->table('relay_mailbox')
            ->where('expires_at', '<', $now)
            ->delete();
    }
}
