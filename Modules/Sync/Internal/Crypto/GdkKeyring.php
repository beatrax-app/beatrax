<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use InvalidArgumentException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
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

    // Returns a NEW keyring with $epoch appended — never mutates $this or
    // discards any existing epoch.
    public function withEpoch(GdkEpoch $epoch): self
    {
        return new self([...$this->epochs, $epoch]);
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
