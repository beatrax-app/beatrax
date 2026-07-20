<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogEntry
{
    // $value is ?string (JSON-serialised); null signals a tombstone/SET-NULL
    // op. $gdkEpoch tags a GDK-encrypted $value with the decrypting epoch —
    // DELIBERATELY EXCLUDED from signingPayload() since epoch authenticity
    // is carried via the AEAD associated data, a separate channel from Ed25519.
    public function __construct(
        public string $table,
        public int|string $pk,
        public string $field,
        public ?string $value,
        public int $hlcL,
        public int $hlcC,
        public string $deviceId,
        public OpType $opType,
        public string $signature,
        public int $userId,
        public ?int $gdkEpoch = null,
    ) {}

    // Deterministic JSON serialisation of all fields EXCEPT $signature — the
    // payload signed by DeviceKeySigner and verified during replay.
    // JSON_UNESCAPED_UNICODE keeps non-ASCII counterparty names readable.
    public function signingPayload(): string
    {
        return json_encode(
            [
                'table' => $this->table,
                'pk' => $this->pk,
                'field' => $this->field,
                'value' => $this->value,
                'hlc_l' => $this->hlcL,
                'hlc_c' => $this->hlcC,
                'device_id' => $this->deviceId,
                'op_type' => $this->opType->value,
                'user_id' => $this->userId,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }
}
