<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Frame;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

final class TransportFramer
{
    private const MAX_PAYLOAD_BYTES = 65536;

    private const MAX_OPS_PER_FRAME = 1024;

    /**
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

        return pack('V', strlen($payload)).$payload;
    }

    /**
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

    // Mirrors OpLogEntry::signingPayload()'s field order for auditability
    // but adds the signature key explicitly (not part of the signed payload).
    /**
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
            // The id the entry was SIGNED under, not this device's local
            // scope. A relayed entry carrying the re-scoped id verifies
            // nowhere, because a v1 signature covers user_id.
            'user_id' => $entry->originUserId ?? $entry->userId,
            // The GDK epoch tag doubles op-log-value encryption as transport
            // encryption — it MUST travel alongside the ciphertext, or the
            // receiving peer cannot decrypt a sensitive field's value at
            // all. Nullable: null means plaintext.
            'gdk_epoch' => $entry->gdkEpoch,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws \UnexpectedValueException on missing or invalid fields.
     */
    private function arrayToEntry(array $row): OpLogEntry
    {
        $this->assertRequiredKeys($row);

        return new OpLogEntry(
            table: $this->requireString($row, 'table'),
            pk: $this->parsePk($row),
            field: $this->requireString($row, 'field'),
            value: $this->parseValue($row),
            hlcL: $this->requireInt($row, 'hlc_l'),
            hlcC: $this->requireInt($row, 'hlc_c'),
            deviceId: $this->requireString($row, 'device_id'),
            opType: $this->parseOpType($row),
            signature: $this->requireString($row, 'signature'),
            userId: $this->requireInt($row, 'user_id'),
            gdkEpoch: $this->parseGdkEpoch($row),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertRequiredKeys(array $row): void
    {
        $required = ['table', 'pk', 'field', 'hlc_l', 'hlc_c', 'device_id', 'op_type', 'signature', 'user_id'];

        foreach ($required as $key) {
            if (! array_key_exists($key, $row)) {
                throw new \UnexpectedValueException(
                    "TransportFramer::decode — missing required field '{$key}' in op entry."
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value)) {
            throw new \UnexpectedValueException("TransportFramer::decode — {$key} must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (! is_int($value)) {
            throw new \UnexpectedValueException("TransportFramer::decode — {$key} must be an int.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseOpType(array $row): OpType
    {
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

        return $opType;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parsePk(array $row): int|string
    {
        $pkRaw = $row['pk'];
        if (! is_int($pkRaw) && ! is_string($pkRaw)) {
            throw new \UnexpectedValueException('TransportFramer::decode — pk must be int or string.');
        }

        return $pkRaw;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseValue(array $row): ?string
    {
        $valueRaw = $row['value'] ?? null;
        if ($valueRaw !== null && ! is_string($valueRaw)) {
            throw new \UnexpectedValueException('TransportFramer::decode — value must be a string or null.');
        }

        return $valueRaw;
    }

    // Optional, backward-compatible with older wire frames that never carried
    // this key: null means the value is plaintext.
    /**
     * @param  array<string, mixed>  $row
     */
    private function parseGdkEpoch(array $row): ?int
    {
        $gdkEpochRaw = $row['gdk_epoch'] ?? null;
        if ($gdkEpochRaw !== null && ! is_int($gdkEpochRaw)) {
            throw new \UnexpectedValueException('TransportFramer::decode — gdk_epoch must be an int or null.');
        }

        return $gdkEpochRaw;
    }
}
