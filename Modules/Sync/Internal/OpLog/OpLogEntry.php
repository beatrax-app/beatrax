<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

final readonly class OpLogEntry
{
    // Carried INSIDE the payload, so a v1 signature can never be replayed as
    // a v2 one. v2 dropped user_id: a per-device autoincrement, so requiring
    // both sides to agree meant sync only worked between devices numbered
    // alike. Trust rests on the Ed25519 device key instead.
    public const int SIGNING_VERSION = 2;

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
        // The user id the ORIGIN device signed under. A v1 signature covers
        // user_id, and that id is a per-device autoincrement, so re-scoping
        // an entry onto this device's own user makes its own signature
        // unverifiable ever after. Null means it was signed under $userId.
        public ?int $originUserId = null,
    ) {}

    // Deterministic JSON serialisation of all fields EXCEPT $signature — the
    // payload signed by DeviceKeySigner and verified during replay.
    // JSON_UNESCAPED_UNICODE keeps non-ASCII counterparty names readable.
    public function signingPayload(): string
    {
        return json_encode(
            [
                'v' => self::SIGNING_VERSION,
                'table' => $this->table,
                'pk' => $this->pk,
                'field' => $this->field,
                'value' => $this->value,
                'hlc_l' => $this->hlcL,
                'hlc_c' => $this->hlcC,
                'device_id' => $this->deviceId,
                'op_type' => $this->opType->value,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    // The v1 shape, kept verbatim so entries signed before the version marker
    // existed still verify. Never used to SIGN — only as the second candidate
    // when checking an existing signature.
    public function legacySigningPayload(): string
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
                'user_id' => $this->originUserId ?? $this->userId,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    // Both shapes a stored signature may have been produced under, newest
    // first. A v1 entry only ever exists on the device that wrote it, since a
    // v1 entry could never survive a peer's user-id gate to be stored anywhere
    // else — so the fallback costs one extra hash on local history only.
    /**
     * @return list<string>
     */
    public function signatureCandidates(): array
    {
        return [$this->signingPayload(), $this->legacySigningPayload()];
    }

    // Re-scopes an entry onto the RECEIVING device's local user. user_id is a
    // local autoincrement surrogate, not a shared identity: the same account
    // is user 3 on one device and user 1 on another. It is deliberately absent
    // from the v2 signing payload, so re-scoping never invalidates a signature.
    public function withUserId(int $userId): self
    {
        return $this->userId === $userId ? $this : new self(
            table: $this->table,
            pk: $this->pk,
            field: $this->field,
            value: $this->value,
            hlcL: $this->hlcL,
            hlcC: $this->hlcC,
            deviceId: $this->deviceId,
            opType: $this->opType,
            signature: $this->signature,
            userId: $userId,
            gdkEpoch: $this->gdkEpoch,
            // Remembers what was signed, so the entry stays verifiable after
            // it is re-scoped onto the local user.
            originUserId: $this->originUserId ?? $this->userId,
        );
    }
}
