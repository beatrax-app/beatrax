<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;

/**
 * HLC-watermark catch-up protocol between two syncing peers.
 *
 * ## Protocol (RESEARCH Pattern 6)
 *
 *   Initiator → Responder: CATCH_UP_REQUEST { hlc_l, hlc_c, device_id, user_id }
 *   Responder → Initiator: CATCH_UP_RESPONSE { ops: [...] where (hlc_l,hlc_c) > watermark }
 *   Initiator → Responder: CATCH_UP_REQUEST (symmetric — responder sends its own watermark)
 *   Responder → Initiator: CATCH_UP_RESPONSE (symmetric)
 *   Both sides: CATCH_UP_COMPLETE
 *   Both sides: switch to LIVE_STREAM mode
 *
 * ## HLC watermark
 *
 *   The local HLC watermark is read from hlc_clock_state for the given (user_id, device_id)
 *   pair. This tells the peer "I have seen all ops up through (hlc_l, hlc_c); send me anything
 *   newer."
 *
 * ## Op fetch query
 *
 *   Fetches op_log_entries WHERE user_id = $userId AND
 *     (hlc_l > peerHlcL) OR (hlc_l = peerHlcL AND hlc_c > peerHlcC),
 *   ordered by (hlc_l, hlc_c, device_id) for deterministic delivery.
 *
 * ## Batching
 *
 *   Ops are batched into TransportFramer frames of ≤ 64KB / ≤ 1024 ops each
 *   (RESEARCH Pattern 6). Large syncs (first onboarding of a new device) span many frames.
 *
 * ## Security
 *
 *   - All queries are user_id-scoped (T-13-11, I2 guard): catch-up for user A never
 *     returns ops belonging to user B.
 *   - Op signature re-verification is the caller's (SyncSession's) responsibility AFTER
 *     receiving frames. This class only reads from op_log_entries; it does not verify sigs.
 *
 * @internal Plan 04 — used by SyncWebSocketHandler.
 */
final readonly class PeerCatchUpExchanger
{
    /**
     * Maximum number of ops per catch-up frame (matches TransportFramer::MAX_OPS_PER_FRAME).
     */
    private const int BATCH_OPS = 1024;

    /**
     * Catch-up message types (wire framing within the Noise session).
     */
    public const string MSG_CATCH_UP_REQUEST = 'CATCH_UP_REQUEST';

    public const string MSG_CATCH_UP_RESPONSE = 'CATCH_UP_RESPONSE';

    public const string MSG_CATCH_UP_COMPLETE = 'CATCH_UP_COMPLETE';

    public function __construct(
        private DatabaseManager $db,
        private TransportFramer $framer,
    ) {}

    /**
     * Build a CATCH_UP_REQUEST payload from the local HLC watermark.
     *
     * Reads hlc_clock_state for (user_id, device_id) to find the local high-water mark.
     * If no state exists (first sync), watermark is (0, 0) meaning "send me everything".
     *
     * @return array{type: string, hlc_l: int, hlc_c: int, device_id: string, user_id: int}
     */
    public function buildRequest(int $userId, string $deviceId): array
    {
        $state = $this->db->connection()
            ->table('hlc_clock_state')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();

        $lastL = 0;
        $lastC = 0;
        if ($state !== null) {
            $lastL = is_numeric($state->last_l) ? (int) $state->last_l : 0;
            $lastC = is_numeric($state->last_c) ? (int) $state->last_c : 0;
        }

        return [
            'type' => self::MSG_CATCH_UP_REQUEST,
            'hlc_l' => $lastL,
            'hlc_c' => $lastC,
            'device_id' => $deviceId,
            'user_id' => $userId,
        ];
    }

    /**
     * Fetch all op_log_entries for $userId with (hlc_l, hlc_c) > ($peerHlcL, $peerHlcC).
     *
     * Returns ops as batches of TransportFramer frames (≤ 64KB / ≤ 1024 ops per frame).
     * Each frame is a binary string ready for NoiseSession::encrypt().
     *
     * @param  int  $userId  Scope guard — only ops for this user are returned (T-13-11).
     * @param  int  $peerHlcL  Peer's last known hlc_l (logical time).
     * @param  int  $peerHlcC  Peer's last known hlc_c (counter).
     * @return list<string> List of TransportFramer-encoded binary frames (may be empty if no gap).
     */
    public function opsAfterWatermark(int $userId, int $peerHlcL, int $peerHlcC): array
    {
        // Lexicographic HLC comparison: (hlc_l, hlc_c) > ($peerHlcL, $peerHlcC).
        // Pattern from 13-PATTERNS.md "PeerCatchUpExchanger.php" / OpLogWriter analog.
        $rows = $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where(function (Builder $q) use ($peerHlcL, $peerHlcC): void {
                $q->where('hlc_l', '>', $peerHlcL)
                    ->orWhere(function (Builder $q2) use ($peerHlcL, $peerHlcC): void {
                        $q2->where('hlc_l', '=', $peerHlcL)
                            ->where('hlc_c', '>', $peerHlcC);
                    });
            })
            ->orderBy('hlc_l')
            ->orderBy('hlc_c')
            ->orderBy('device_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Deserialize rows to OpLogEntry objects.
        $entries = [];
        foreach ($rows as $row) {
            $opTypeRaw = is_string($row->op_type) ? $row->op_type : '';
            $opType = OpType::tryFrom($opTypeRaw);
            if ($opType === null) {
                continue;
            }

            $pk = is_numeric($row->pk) ? (int) $row->pk : (is_string($row->pk) ? $row->pk : '');
            $entries[] = new OpLogEntry(
                table: is_string($row->table_name) ? $row->table_name : '',
                pk: $pk,
                field: is_string($row->field) ? $row->field : '',
                value: is_string($row->value) ? $row->value : null,
                hlcL: is_numeric($row->hlc_l) ? (int) $row->hlc_l : 0,
                hlcC: is_numeric($row->hlc_c) ? (int) $row->hlc_c : 0,
                deviceId: is_string($row->device_id) ? $row->device_id : '',
                opType: $opType,
                signature: is_string($row->signature) ? $row->signature : '',
                userId: is_numeric($row->user_id) ? (int) $row->user_id : 0,
            );
        }

        if ($entries === []) {
            return [];
        }

        // Batch into TransportFramer frames (≤ BATCH_OPS ops per frame; 64KB byte cap enforced
        // by the framer). We accumulate entries per frame and start a new frame whenever the
        // next entry would push the batch over BATCH_OPS ops or over 64KB serialized bytes.
        // Both limits are required; real-world entries with long signatures/values can hit the
        // byte cap well before 1024 ops (RESEARCH Pattern 6 — "≤ 1024 ops or ≤ 64KB per frame").
        $maxBytes = 65536;
        $frames = [];
        $batch = [];
        $batchBytes = 2; // JSON array overhead: opening + closing bracket

        foreach ($entries as $entry) {
            $entrySerialized = json_encode([
                'table' => $entry->table,
                'pk' => $entry->pk,
                'field' => $entry->field,
                'value' => $entry->value,
                'hlc_l' => $entry->hlcL,
                'hlc_c' => $entry->hlcC,
                'device_id' => $entry->deviceId,
                'op_type' => $entry->opType->value,
                'signature' => $entry->signature,
                'user_id' => $entry->userId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            // +1 for comma separator between array elements.
            $entryBytes = strlen($entrySerialized) + 1;

            $wouldExceedOps = count($batch) >= self::BATCH_OPS;
            $wouldExceedBytes = ($batchBytes + $entryBytes) > $maxBytes;

            if ($batch !== [] && ($wouldExceedOps || $wouldExceedBytes)) {
                $frames[] = $this->framer->encode($batch);
                $batch = [];
                $batchBytes = 2;
            }

            $batch[] = $entry;
            $batchBytes += $entryBytes;
        }

        // After the loop, $batch is guaranteed non-empty because $entries is non-empty
        // (guard at the top of this method) and every entry is appended to $batch after
        // any flush. The check $batch !== [] would always be true here — flush unconditionally.
        $frames[] = $this->framer->encode($batch);

        return $frames;
    }

    /**
     * Build a CATCH_UP_RESPONSE control message wrapping a list of frames.
     *
     * In the wire protocol, a CATCH_UP_RESPONSE carries the frames as-is; this helper
     * builds the JSON control envelope that tells the peer how many frames to expect.
     *
     * @param  list<string>  $frames  Encoded frames from opsAfterWatermark().
     * @return array{type: string, frame_count: int}
     */
    public function buildResponse(array $frames): array
    {
        return [
            'type' => self::MSG_CATCH_UP_RESPONSE,
            'frame_count' => count($frames),
        ];
    }

    /**
     * Build a CATCH_UP_COMPLETE control message.
     *
     * @return array{type: string}
     */
    public function buildComplete(): array
    {
        return ['type' => self::MSG_CATCH_UP_COMPLETE];
    }

    /**
     * Parse a received JSON control message.
     *
     * @param  string  $json  Received control message (plaintext, not a TransportFramer frame).
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException If the message is not a valid catch-up control message.
     */
    public function parseControlMessage(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException(
                'PeerCatchUpExchanger: invalid JSON control message: '.$e->getMessage(),
                0,
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new \UnexpectedValueException(
                'PeerCatchUpExchanger: control message must be a JSON object.'
            );
        }

        /** @var array<string, mixed> $msg */
        $msg = $decoded;

        $type = $msg['type'] ?? null;
        if (! is_string($type)) {
            throw new \UnexpectedValueException(
                'PeerCatchUpExchanger: control message missing "type" string field.'
            );
        }

        return $msg;
    }
}
