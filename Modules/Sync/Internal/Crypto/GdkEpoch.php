<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

/**
 * Immutable {epochId, keyHex} pair — one entry in a user's GDK (Group Data
 * Key) epoch keyring (D-03/D-04).
 *
 * `epochId` is a small monotonically-increasing integer (NOT a UUID —
 * epochs are ordered: a device removal always mints epochId+1). `keyHex` is
 * the raw 32-byte symmetric AEAD key, hex-encoded, for
 * `sodium_crypto_aead_xchacha20poly1305_ietf_*` calls via
 * `OpLogFieldCrypto`/the Sync Public `SensitiveColumnCodec`.
 *
 * NEVER PERSIST THIS DTO OUTSIDE THE KEYRING'S OWN ENCRYPTED JSON FILE
 * (mirrors `DeviceIdentityDto`'s in-memory-only warning). `keyHex` is secret
 * key material — it must only ever live inside the app-lock-KEK-wrapped
 * keyring file written by `GdkKeyringService`, never in the database, never
 * logged, never serialized into a Livewire snapshot or session array beyond
 * what `AppLockKeyWrap`'s wrap already covers.
 */
final readonly class GdkEpoch
{
    public function __construct(
        public int $epochId,
        public string $keyHex,
    ) {}

    /**
     * @return array{epoch_id: int, key_hex: string}
     */
    public function toArray(): array
    {
        return [
            'epoch_id' => $this->epochId,
            'key_hex' => $this->keyHex,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $epochId = $row['epoch_id'] ?? null;
        $keyHex = $row['key_hex'] ?? null;

        if (! is_int($epochId)) {
            throw new \InvalidArgumentException('GdkEpoch::fromArray — epoch_id must be an int.');
        }

        if (! is_string($keyHex) || $keyHex === '') {
            throw new \InvalidArgumentException('GdkEpoch::fromArray — key_hex must be a non-empty string.');
        }

        return new self(epochId: $epochId, keyHex: $keyHex);
    }
}
