<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class GdkEpoch
{
    // NEVER PERSIST THIS DTO OUTSIDE THE KEYRING'S OWN ENCRYPTED JSON FILE.
    // `keyHex` is secret key material — it must only ever live inside the
    // app-lock-KEK-wrapped keyring file GdkKeyringService writes: never in the
    // database, never logged, never serialized into a Livewire snapshot or session array.
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
