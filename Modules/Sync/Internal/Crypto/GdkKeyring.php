<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use InvalidArgumentException;

final readonly class GdkKeyring
{
    // Immutable, append-only in-memory collection of a user's GDK epochs —
    // never persisted outside the app-lock-KEK-wrapped file GdkKeyringService
    // writes. Append-only because OpLogRebuilder::rebuild() replays the
    // entire persisted op-log and must be able to decrypt every historical epoch, forever.
    /**
     * @param  list<GdkEpoch>  $epochs
     */
    private function __construct(
        private array $epochs,
        private ?string $blindIndexKeyHex = null,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<GdkEpoch>
     */
    public function epochs(): array
    {
        return $this->epochs;
    }

    // The blind-index key is NOT an epoch and is never rotated: it keys a
    // one-way digest that a UNIQUE index compares, so a second value for the
    // same merchant would read as a second merchant.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    public function blindIndexKeyHex(): ?string
    {
        return $this->blindIndexKeyHex;
    }

    public function withBlindIndexKey(string $keyHex): self
    {
        return new self($this->epochs, $keyHex);
    }

    // Returns a NEW keyring with $epoch appended — never mutates $this or
    // discards any existing epoch.
    public function withEpoch(GdkEpoch $epoch): self
    {
        return new self([...$this->epochs, $epoch], $this->blindIndexKeyHex);
    }

    // Returns a NEW keyring with the entry for $epoch->epochId swapped for
    // $epoch, preserving order. Append-only protects keys that decrypt
    // history; a joining device's self-minted epoch decrypts nothing, and
    // keeping it shadows the group's real key of the same id forever.
    public function withEpochReplaced(GdkEpoch $epoch): self
    {
        return new self(array_map(
            static fn (GdkEpoch $existing): GdkEpoch => $existing->epochId === $epoch->epochId
                ? $epoch
                : $existing,
            $this->epochs,
        ), $this->blindIndexKeyHex);
    }

    public function keyFor(int $epochId): ?string
    {
        foreach ($this->epochs as $epoch) {
            if ($epoch->epochId === $epochId) {
                return $epoch->keyHex;
            }
        }

        return null;
    }

    // The key is omitted rather than written null when absent, so a keyring
    // file this build writes still parses under a build that predates it.
    /**
     * @return array{epochs: list<array{epoch_id: int, key_hex: string}>, blind_index_key_hex?: string}
     */
    public function toArray(): array
    {
        $payload = [
            'epochs' => array_map(
                static fn (GdkEpoch $epoch): array => $epoch->toArray(),
                $this->epochs,
            ),
        ];

        if ($this->blindIndexKeyHex !== null) {
            $payload['blind_index_key_hex'] = $this->blindIndexKeyHex;
        }

        return $payload;
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

        $blindIndexKeyHex = $payload['blind_index_key_hex'] ?? null;
        if ($blindIndexKeyHex !== null && (! is_string($blindIndexKeyHex) || $blindIndexKeyHex === '')) {
            throw new InvalidArgumentException('GdkKeyring::fromArray — blind_index_key_hex must be a non-empty string when present.');
        }

        return new self($epochs, $blindIndexKeyHex);
    }
}
