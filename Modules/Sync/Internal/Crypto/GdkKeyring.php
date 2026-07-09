<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use InvalidArgumentException;

/**
 * Immutable, append-only in-memory collection of a user's GDK epochs (D-04).
 *
 * Mirrors `DeviceIdentityDto`'s "never persist outside the sanctioned
 * encrypted key-file" posture — this VO is the in-memory shape
 * `GdkKeyringService` decrypts a keyring file into and re-encrypts a
 * keyring file from. It is never itself serialized anywhere except inside
 * the app-lock-KEK-wrapped file `GdkKeyringService` writes.
 *
 * Append-only by construction: `withEpoch()` returns a NEW GdkKeyring with
 * the epoch appended, never mutating or discarding any prior epoch
 * (14-RESEARCH.md Pitfall 4 — `OpLogRebuilder::rebuild()` replays the
 * entire persisted op-log and must be able to decrypt every historical
 * epoch, forever).
 */
final readonly class GdkKeyring
{
    /**
     * @param  list<GdkEpoch>  $epochs
     */
    private function __construct(
        private array $epochs,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  list<GdkEpoch>  $epochs
     */
    public static function fromEpochs(array $epochs): self
    {
        return new self($epochs);
    }

    /**
     * @return list<GdkEpoch>
     */
    public function epochs(): array
    {
        return $this->epochs;
    }

    /**
     * Returns a NEW keyring with $epoch appended — never mutates $this or
     * discards any existing epoch.
     */
    public function withEpoch(GdkEpoch $epoch): self
    {
        return new self([...$this->epochs, $epoch]);
    }

    /**
     * The raw key hex for a given epoch id, or null when the keyring holds
     * no such epoch.
     */
    public function keyFor(int $epochId): ?string
    {
        foreach ($this->epochs as $epoch) {
            if ($epoch->epochId === $epochId) {
                return $epoch->keyHex;
            }
        }

        return null;
    }

    /**
     * @return array{epochs: list<array{epoch_id: int, key_hex: string}>}
     */
    public function toArray(): array
    {
        return [
            'epochs' => array_map(
                static fn (GdkEpoch $epoch): array => $epoch->toArray(),
                $this->epochs,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $rows = $payload['epochs'] ?? null;
        if (! is_array($rows)) {
            throw new InvalidArgumentException('GdkKeyring::fromArray — payload must contain an "epochs" array.');
        }

        $epochs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('GdkKeyring::fromArray — each epoch row must be an array.');
            }

            /** @var array<string, mixed> $row */
            $epochs[] = GdkEpoch::fromArray($row);
        }

        return new self($epochs);
    }
}
