<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;

final readonly class RelayMailbox
{
    // HARD ZK INVARIANT: stores and routes opaque Noise ciphertext blobs by
    // recipient device_id. MUST NEVER call sodium_*/json_decode() on the
    // blob, read/write any user_id column, or inspect blob content.
    // Authorization is enforced by the relay:serve endpoint, NOT here.

    private const int UNDELIVERED_TTL_DAYS = 30;

    private const int DELIVERED_TTL_DAYS = 7;

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
        $now = Instant::zulu($this->clock->now());
        $expiresAt = Instant::zulu($this->clock->now()->addDays(self::UNDELIVERED_TTL_DAYS));

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
    /**
     * @param  bool  $foldIdentical  Whether an identical frame already waiting
     *                               for this recipient counts as stored. A
     *                               pairing re-emit is byte-identical by
     *                               design; see {@see self::alreadyPending()}.
     */
    public function deliverIfUnderQuota(string $senderDid, string $recipientDid, string $blob, int $maxPending, bool $foldIdentical = false): bool
    {
        return $this->db->connection()->transaction(function () use ($senderDid, $recipientDid, $blob, $maxPending, $foldIdentical): bool {
            if ($foldIdentical && $this->alreadyPending($recipientDid, $blob)) {
                return true;
            }

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

    // A frame that is already waiting is already stored, so re-queueing it
    // adds a duplicate and nothing else. Sixteen identical pairing accepts
    // once spent a peer's whole quota on one frame, and every later frame to
    // that peer — including the confirm — was refused for a month.
    private function alreadyPending(string $recipientDid, string $blob): bool
    {
        return $this->db->connection()
            ->table('relay_mailbox')
            ->where('recipient_did', $recipientDid)
            ->whereNull('delivered_at')
            ->where('blob', $blob)
            ->exists();
    }

    // Bounded (resource-exhaustion guard): an unbounded drain would hand the
    // caller the whole pending backlog at once. The cursor is what lets that
    // caller page PAST a row it cannot retire — a deferred wrap and another
    // protocol's blob both stay pending, and would otherwise hold the window.
    /**
     * @param  string|null  $afterCreatedAt  Cursor half: exclusive lower bound on created_at.
     * @param  int|null  $afterId  Cursor half: exclusive lower bound on id within one created_at.
     * @return list<\stdClass> Pending mailbox rows (id, sender_did, recipient_did, blob, created_at, expires_at)
     */
    public function drain(string $recipientDid, int $limit, ?string $afterCreatedAt = null, ?int $afterId = null): array
    {
        $query = $this->db->connection()
            ->table('relay_mailbox')
            ->where('recipient_did', $recipientDid)
            ->whereNull('delivered_at');

        if ($afterCreatedAt !== null && $afterId !== null) {
            $query->where(static function (Builder $cursor) use ($afterCreatedAt, $afterId): void {
                $cursor->where('created_at', '>', $afterCreatedAt)
                    ->orWhere(static function (Builder $tie) use ($afterCreatedAt, $afterId): void {
                        $tie->where('created_at', $afterCreatedAt)->where('id', '>', $afterId);
                    });
            });
        }

        /** @var list<\stdClass> $rows */
        $rows = $query
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
        $now = Instant::zulu($this->clock->now());
        $newExpiresAt = Instant::zulu($this->clock->now()->addDays(self::DELIVERED_TTL_DAYS));

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
        $now = Instant::zulu($this->clock->now());

        return $this->db->connection()
            ->table('relay_mailbox')
            ->where('expires_at', '<', $now)
            ->delete();
    }
}
