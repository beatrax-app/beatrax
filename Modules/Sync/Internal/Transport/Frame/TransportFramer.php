<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Frame;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

/**
 * Length-prefixed binary codec for OpLogEntry batches.
 *
 * Wire format (per frame):
 *   [4 bytes: uint32 LE payload length][payload bytes: JSON array of op-log entries]
 *
 * Each op-log entry is serialized to a JSON object preserving all 10 constructor
 * fields plus the Ed25519 signature. The signature is included to allow the receiver
 * to verify it via DeviceKeySigner::verify() after decryption (transport encryption
 * is additive to op-log signatures, not a replacement — RESEARCH Pitfall 7).
 *
 * Constraints:
 *   - Maximum frame payload: 65,536 bytes (64 KB) per RESEARCH Pattern 6.
 *   - Maximum ops per frame: 1,024 entries per RESEARCH Pattern 6.
 *   - Oversized frames are rejected on encode (throw) and decode (throw).
 *
 * encode() → binary string ready for NoiseSession::encrypt()
 * decode() → list<OpLogEntry> ready for OpLogReplayer::replay()
 *
 * The signature field is the hex-encoded Ed25519 detached signature that was set
 * when the entry was originally signed (OpLogEntry::$signature). Round-tripping
 * through encode/decode must preserve this field exactly.
 */
final class TransportFramer
{
    private const MAX_PAYLOAD_BYTES = 65536;  // 64 KB

    private const MAX_OPS_PER_FRAME = 1024;

    /**
     * Encodes a list of OpLogEntry objects into a length-prefixed binary frame.
     *
     * @param  list<OpLogEntry>  $entries  Must not be empty and must not exceed MAX_OPS_PER_FRAME.
     * @return string Binary frame: [uint32 LE length][JSON payload].
     *
     * @throws \InvalidArgumentException if $entries exceeds the cap.
     * @throws \OverflowException if the JSON payload exceeds MAX_PAYLOAD_BYTES.
     */
    public function encode(array $entries): string
    {
        if (count($entries) > self::MAX_OPS_PER_FRAME) {
            throw new \InvalidArgumentException(sprintf(
                'TransportFramer::encode — too many ops (%d). Maximum is %d per frame.',
                count($entries),
                self::MAX_OPS_PER_FRAME,
            ));
        }

        $payload = json_encode(
            array_map([$this, 'entryToArray'], $entries),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \OverflowException(sprintf(
                'TransportFramer::encode — payload too large (%d bytes). Maximum is %d bytes.',
                strlen($payload),
                self::MAX_PAYLOAD_BYTES,
            ));
        }

        // 4-byte LE uint32 length prefix + payload
        return pack('V', strlen($payload)).$payload;
    }

    /**
     * Decodes a length-prefixed binary frame back into a list of OpLogEntry objects.
     *
     * @param  string  $frame  Binary frame from encode() (or received over the wire).
     * @return list<OpLogEntry>
     *
     * @throws \UnexpectedValueException if the frame is malformed, truncated, or oversized.
     */
    public function decode(string $frame): array
    {
        if (strlen($frame) < 4) {
            throw new \UnexpectedValueException(
                'TransportFramer::decode — frame too short (< 4 bytes length prefix).'
            );
        }

        /** @var array{len: int} $unpacked */
        $unpacked = unpack('Vlen', substr($frame, 0, 4));
        $length = $unpacked['len'];

        if ($length > self::MAX_PAYLOAD_BYTES) {
            throw new \UnexpectedValueException(sprintf(
                'TransportFramer::decode — declared payload length %d exceeds maximum %d bytes.',
                $length,
                self::MAX_PAYLOAD_BYTES,
            ));
        }

        if (strlen($frame) !== 4 + $length) {
            throw new \UnexpectedValueException(sprintf(
                'TransportFramer::decode — frame length mismatch: header says %d bytes, got %d bytes of payload.',
                $length,
                strlen($frame) - 4,
            ));
        }

        $payload = substr($frame, 4, $length);

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException(
                'TransportFramer::decode — invalid JSON payload: '.$e->getMessage(),
                0,
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new \UnexpectedValueException('TransportFramer::decode — payload is not a JSON array.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($decoded);

        if (count($rows) > self::MAX_OPS_PER_FRAME) {
            throw new \UnexpectedValueException(sprintf(
                'TransportFramer::decode — too many ops in frame (%d). Maximum is %d.',
                count($rows),
                self::MAX_OPS_PER_FRAME,
            ));
        }

        return array_map([$this, 'arrayToEntry'], $rows);
    }

    /**
     * Serializes one OpLogEntry to an associative array for JSON encoding.
     *
     * Includes all 10 constructor fields + the signature (which is NOT in the
     * signingPayload but IS a constructor field). This mirrors OpLogEntry::signingPayload()
     * field order for auditability but adds the signature key explicitly.
     *
     * @return array<string, mixed>
     */
    private function entryToArray(OpLogEntry $entry): array
    {
        return [
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
        ];
    }

    /**
     * Deserializes one row from JSON back into an OpLogEntry.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws \UnexpectedValueException on missing or invalid fields.
     */
    private function arrayToEntry(array $row): OpLogEntry
    {
        $required = ['table', 'pk', 'field', 'hlc_l', 'hlc_c', 'device_id', 'op_type', 'signature', 'user_id'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $row)) {
                throw new \UnexpectedValueException(
                    "TransportFramer::decode — missing required field '{$key}' in op entry."
                );
            }
        }

        $opTypeRaw = $row['op_type'];
        if (! is_string($opTypeRaw) && ! is_int($opTypeRaw)) {
            throw new \UnexpectedValueException(
                'TransportFramer::decode — op_type field is not a string or int.'
            );
        }

        $opType = OpType::tryFrom((string) $opTypeRaw);
        if ($opType === null) {
            throw new \UnexpectedValueException(
                "TransportFramer::decode — unknown op_type: '{$opTypeRaw}'."
            );
        }

        // pk can be int or string (JSON number or string)
        $pkRaw = $row['pk'];
        if (! is_int($pkRaw) && ! is_string($pkRaw)) {
            throw new \UnexpectedValueException('TransportFramer::decode — pk must be int or string.');
        }
        $pk = $pkRaw;

        $table = $row['table'];
        if (! is_string($table)) {
            throw new \UnexpectedValueException('TransportFramer::decode — table must be a string.');
        }

        $field = $row['field'];
        if (! is_string($field)) {
            throw new \UnexpectedValueException('TransportFramer::decode — field must be a string.');
        }

        $valueRaw = $row['value'] ?? null;
        if ($valueRaw !== null && ! is_string($valueRaw)) {
            throw new \UnexpectedValueException('TransportFramer::decode — value must be a string or null.');
        }
        $value = $valueRaw;

        $hlcL = $row['hlc_l'];
        if (! is_int($hlcL)) {
            throw new \UnexpectedValueException('TransportFramer::decode — hlc_l must be an int.');
        }

        $hlcC = $row['hlc_c'];
        if (! is_int($hlcC)) {
            throw new \UnexpectedValueException('TransportFramer::decode — hlc_c must be an int.');
        }

        $deviceId = $row['device_id'];
        if (! is_string($deviceId)) {
            throw new \UnexpectedValueException('TransportFramer::decode — device_id must be a string.');
        }

        $signature = $row['signature'];
        if (! is_string($signature)) {
            throw new \UnexpectedValueException('TransportFramer::decode — signature must be a string.');
        }

        $userId = $row['user_id'];
        if (! is_int($userId)) {
            throw new \UnexpectedValueException('TransportFramer::decode — user_id must be an int.');
        }

        return new OpLogEntry(
            table: $table,
            pk: $pk,
            field: $field,
            value: $value,
            hlcL: $hlcL,
            hlcC: $hlcC,
            deviceId: $deviceId,
            opType: $opType,
            signature: $signature,
            userId: $userId,
        );
    }
}
