<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Frame;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

final class TransportFramer
{
    // Public because the caller that PACKS batches has to predict the same
    // ceiling this class ENFORCES. Two copies meant a batch packed against one
    // number and rejected by the other, mid-catch-up, as an OverflowException
    // nothing on that path catches.
    public const int MAX_PAYLOAD_BYTES = 65536;

    public const int MAX_OPS_PER_FRAME = 1024;

    public const int LENGTH_PREFIX_BYTES = 4;

    // A serialized batch opens and closes with the JSON array brackets, and
    // every entry after the first costs one comma separator.
    private const int JSON_ARRAY_BRACKETS_BYTES = 2;

    private const int JSON_SEPARATOR_BYTES = 1;

    /** @var \WeakMap<OpLogEntry, int> */
    private \WeakMap $entrySizes;

    public function __construct()
    {
        $this->entrySizes = new \WeakMap;
    }

    // Whether appending $next would push $batch past either ceiling, answered
    // by the class that throws when it does. A caller re-deriving the size from
    // its own copy of entryToArray() is how the budget came to be predicted
    // against a payload carrying a different user_id than encode() emits.
    /**
     * @param  list<OpLogEntry>  $batch
     */
    public function wouldOverflow(array $batch, OpLogEntry $next): bool
    {
        if (count($batch) >= self::MAX_OPS_PER_FRAME) {
            return true;
        }

        return $this->payloadBytes([...$batch, $next]) > self::MAX_PAYLOAD_BYTES;
    }

    // Whether this entry overflows a frame ON ITS OWN, which no packing can
    // rescue: one entry is the smallest batch there is, so a caller that keeps
    // starting a new frame for it only ever reaches encode()'s throw. Asked of
    // the framer for the same reason wouldOverflow() is — it owns the ceiling.
    public function exceedsFrameBudget(OpLogEntry $entry): bool
    {
        return $this->payloadBytes([$entry]) > self::MAX_PAYLOAD_BYTES;
    }

    // The exact byte length encode() would produce for this batch, minus the
    // length prefix — the same json_encode of the same array, counted rather
    // than built.
    /**
     * @param  list<OpLogEntry>  $entries
     */
    public function payloadBytes(array $entries): int
    {
        $bytes = self::JSON_ARRAY_BRACKETS_BYTES
            + max(0, count($entries) - 1) * self::JSON_SEPARATOR_BYTES;

        foreach ($entries as $entry) {
            $bytes += $this->sizeOf($entry);
        }

        return $bytes;
    }

    // Memoised on the entry object itself: packIntoFrames() asks about the
    // same entries once per candidate batch, and re-encoding each of them
    // every time turned a one-pass walk into a quadratic one.
    public function sizeOf(OpLogEntry $entry): int
    {
        $cached = $this->entrySizes[$entry] ?? null;
        if (is_int($cached)) {
            return $cached;
        }

        $size = strlen(json_encode(
            $this->entryToArray($entry),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        $this->entrySizes[$entry] = $size;

        return $size;
    }

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
        if (strlen($frame) < self::LENGTH_PREFIX_BYTES) {
            throw new \UnexpectedValueException(sprintf(
                'TransportFramer::decode — frame too short (< %d bytes length prefix).',
                self::LENGTH_PREFIX_BYTES,
            ));
        }

        /** @var array{len: int} $unpacked */
        $unpacked = unpack('Vlen', substr($frame, 0, self::LENGTH_PREFIX_BYTES));
        $length = $unpacked['len'];

        if ($length > self::MAX_PAYLOAD_BYTES) {
            throw new \UnexpectedValueException(sprintf(
                'TransportFramer::decode — declared payload length %d exceeds maximum %d bytes.',
                $length,
                self::MAX_PAYLOAD_BYTES,
            ));
        }

        if (strlen($frame) !== self::LENGTH_PREFIX_BYTES + $length) {
            throw new \UnexpectedValueException(sprintf(
                'TransportFramer::decode — frame length mismatch: header says %d bytes, got %d bytes of payload.',
                $length,
                strlen($frame) - self::LENGTH_PREFIX_BYTES,
            ));
        }

        $payload = substr($frame, self::LENGTH_PREFIX_BYTES, $length);

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

        $rows = array_values($decoded);

        if (count($rows) > self::MAX_OPS_PER_FRAME) {
            throw new \UnexpectedValueException(sprintf(
                'TransportFramer::decode — too many ops in frame (%d). Maximum is %d.',
                count($rows),
                self::MAX_OPS_PER_FRAME,
            ));
        }

        return array_map(
            fn (mixed $row): OpLogEntry => $this->arrayToEntry($this->requireRow($row)),
            $rows,
        );
    }

    // A JSON array proves nothing about its elements, and the docblock above
    // promises every malformed frame arrives as one exception type. Handing a
    // list of scalars straight to arrayToEntry() raised a TypeError instead,
    // contained only because both call sites happen to catch Throwable.
    /**
     * @return array<string, mixed>
     */
    private function requireRow(mixed $row): array
    {
        if (! is_array($row)) {
            throw new \UnexpectedValueException(
                'TransportFramer::decode — op entry must be a JSON object.'
            );
        }

        /** @var array<string, mixed> $row */
        return $row;
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
