<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

/**
 * Immutable op-log entry DTO — one field-level change signed by the
 * originating device.
 *
 * $value is ?string (JSON-serialised new value) rather than mixed so the
 * class passes PHPStan level 10 strict mode. Callers must JSON-encode
 * non-string values before constructing an entry; null signals a
 * tombstone / SET-NULL op.
 *
 * $signature is a hex-encoded Ed25519 detached signature of
 * signingPayload(). The DeviceKeySigner service signs and verifies.
 *
 * $userId scopes the entry to one user; the replayer enforces this
 * boundary on every DB write (no global Eloquent scope fires in queue
 * or console context — explicit WHERE user_id = ? is the guard).
 *
 * $gdkEpoch (Phase 14, CRYPT-01) tags a GDK-encrypted $value with the epoch
 * id whose key decrypts it. Null means $value is plaintext (either the
 * field is not sensitive, or GDK encryption was not yet enabled when the
 * op was written). Epoch authenticity is carried via the AEAD associated
 * data bound into $value's ciphertext (see OpLogFieldCrypto) — a SEPARATE
 * authentication channel from the Ed25519 signature below. $gdkEpoch is
 * therefore DELIBERATELY EXCLUDED from signingPayload(): adding it there
 * would be a wire-format/signature-compatibility change, not required for
 * the epoch-tamper defense this phase needs.
 */
final readonly class OpLogEntry
{
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

    /**
     * Deterministic JSON serialisation of all fields EXCEPT $signature.
     * This is the payload signed by DeviceKeySigner and verified during
     * replay — same pattern as ElectronUpdateChannel::verifyManifest().
     *
     * JSON_THROW_ON_ERROR: any encoding failure raises JsonException
     * rather than returning false (PHPStan level 10 forbids ignoring the
     * string|false return without checking).
     *
     * JSON_UNESCAPED_UNICODE: keeps non-ASCII counterparty names readable
     * in logs and snapshots.
     */
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
