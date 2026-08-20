<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class PeerCatchUpExchanger
{
    private const int BATCH_OPS = 1024;

    private const int MAX_FRAME_BYTES = 65536;

    // A serialized batch opens and closes with the JSON array brackets, and
    // every appended entry costs one comma separator — both counted so the
    // byte budget matches what TransportFramer will actually emit.
    private const int JSON_ARRAY_BRACKETS_BYTES = 2;

    private const int JSON_SEPARATOR_BYTES = 1;

    public const string MSG_CATCH_UP_REQUEST = 'CATCH_UP_REQUEST';

    public const string MSG_CATCH_UP_RESPONSE = 'CATCH_UP_RESPONSE';

    public const string MSG_CATCH_UP_COMPLETE = 'CATCH_UP_COMPLETE';

    public function __construct(
        private DatabaseManager $db,
        private TransportFramer $framer,
    ) {}

    // If no hlc_clock_state row exists (first sync), watermark is (0, 0)
    // meaning "send me everything".
    /**
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
     * @param  int  $userId  Scope guard — only ops for this user are returned.
     * @param  int  $peerHlcL  Peer's last known hlc_l (logical time).
     * @param  int  $peerHlcC  Peer's last known hlc_c (counter).
     * @return list<string> List of TransportFramer-encoded binary frames (may be empty if no gap).
     */
    public function opsAfterWatermark(int $userId, int $peerHlcL, int $peerHlcC): array
    {
        $rows = $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            // An entry signed by a device the registry no longer holds cannot
            // be verified by anyone, so sending it only wastes the wire and
            // fills the peer's log with drops: one import shipped 12,948
            // entries and the phone refused 12,476 of them.
            ->whereIn('device_id', function (Builder $authors) use ($userId): void {
                $authors->select('device_id')
                    ->from('device_registry')
                    ->where('user_id', $userId)
                    ->whereNotNull('confirmed_at');
            })
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

        $entries = $this->mapRowsToEntries($rows);
        if ($entries === []) {
            return [];
        }

        return $this->packIntoFrames($entries);
    }

    // Skips any row whose op_type is not a known OpType — an unknown op can
    // never be safely replayed by a peer, so it is dropped from the exchange.
    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<OpLogEntry>
     */
    private function mapRowsToEntries(Collection $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $entry = $this->mapRowToEntry($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    // Maps one op_log_entries row to an OpLogEntry, or null when its op_type
    // is not a known OpType — an unknown op can never be safely replayed by a
    // peer, so it is dropped from the exchange.
    private function mapRowToEntry(\stdClass $row): ?OpLogEntry
    {
        $opType = OpType::tryFrom(is_string($row->op_type) ? $row->op_type : '');
        if ($opType === null) {
            return null;
        }

        return new OpLogEntry(
            table: is_string($row->table_name) ? $row->table_name : '',
            pk: self::normalizePk($row->pk),
            field: is_string($row->field) ? $row->field : '',
            value: is_string($row->value) ? $row->value : null,
            hlcL: is_numeric($row->hlc_l) ? (int) $row->hlc_l : 0,
            hlcC: is_numeric($row->hlc_c) ? (int) $row->hlc_c : 0,
            deviceId: is_string($row->device_id) ? $row->device_id : '',
            opType: $opType,
            signature: is_string($row->signature) ? $row->signature : '',
            userId: is_numeric($row->user_id) ? (int) $row->user_id : 0,
            originUserId: is_numeric($row->origin_user_id ?? null) ? (int) $row->origin_user_id : null,
            // The GDK epoch tag MUST travel with the ciphertext over the
            // wire — the op-log value column doubles as the
            // transport-encrypted payload; dropping this here would
            // leave the receiving peer unable to decrypt it.
            gdkEpoch: property_exists($row, 'gdk_epoch') && is_numeric($row->gdk_epoch) ? (int) $row->gdk_epoch : null,
        );
    }

    // Accumulates entries per frame, starting a new frame whenever the next
    // entry would push the batch over BATCH_OPS ops or over MAX_FRAME_BYTES
    // serialized bytes. Both limits matter: entries with long signatures or
    // values can hit the byte cap well before the op count.
    /**
     * @param  list<OpLogEntry>  $entries  Must be non-empty.
     * @return list<string>
     */
    private function packIntoFrames(array $entries): array
    {
        $frames = [];
        $batch = [];
        $batchBytes = self::JSON_ARRAY_BRACKETS_BYTES;

        foreach ($entries as $entry) {
            $entryBytes = strlen($this->serializeEntry($entry)) + self::JSON_SEPARATOR_BYTES;

            $wouldExceedOps = count($batch) >= self::BATCH_OPS;
            $wouldExceedBytes = ($batchBytes + $entryBytes) > self::MAX_FRAME_BYTES;

            if ($batch !== [] && ($wouldExceedOps || $wouldExceedBytes)) {
                $frames[] = $this->framer->encode($batch);
                $batch = [];
                $batchBytes = self::JSON_ARRAY_BRACKETS_BYTES;
            }

            $batch[] = $entry;
            $batchBytes += $entryBytes;
        }

        // $batch is guaranteed non-empty here since $entries is non-empty
        // and every entry is appended after any flush — flush unconditionally.
        $frames[] = $this->framer->encode($batch);

        return $frames;
    }

    private function serializeEntry(OpLogEntry $entry): string
    {
        return json_encode([
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
            'gdk_epoch' => $entry->gdkEpoch,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    // A numeric pk normalises to int; a non-numeric string pk (composite or
    // UUID key) is preserved verbatim; anything else collapses to ''.
    private static function normalizePk(mixed $pkRaw): int|string
    {
        if (is_numeric($pkRaw)) {
            return (int) $pkRaw;
        }

        return is_string($pkRaw) ? $pkRaw : '';
    }

    /**
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
     * @return array{type: string}
     */
    public function buildComplete(): array
    {
        return ['type' => self::MSG_CATCH_UP_COMPLETE];
    }

    /**
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
