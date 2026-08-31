<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

// One `(hlc_l, hlc_c)` per AUTHOR device, as delivered by one peer, and the
// wire shape that carries them. The parse and the clamp live here rather than
// in each of the two halves of the protocol, which had already drifted once.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#catch-up-an-hlc-watermark-exchange
 */
final readonly class PeerCatchUpCursors
{
    /**
     * @param  array<string, array{int, int}>  $byAuthor  author device id => [hlc_l, hlc_c].
     */
    private function __construct(public array $byAuthor) {}

    /**
     * @param  array<string, array{int, int}>  $byAuthor
     */
    public static function of(array $byAuthor): self
    {
        return new self($byAuthor);
    }

    // "Send me everything" — a peer never heard from, or one this side cannot
    // name. Over-asking is recoverable; silently skipping an author is not.
    public static function none(): self
    {
        return new self([]);
    }

    // Every author cursor the peer declared, each clamped to non-negative. A
    // negative hlc_l makes the `> hlc_l` predicate match the entire op log,
    // turning every reconnect into a full-history dump.
    public static function fromWire(mixed $raw): self
    {
        if (! is_array($raw)) {
            return self::none();
        }

        $byAuthor = [];

        foreach ($raw as $cursor) {
            if (! is_array($cursor)) {
                continue;
            }

            $author = $cursor['device_id'] ?? null;

            if (! is_string($author) || $author === '') {
                continue;
            }

            $hlcL = $cursor['hlc_l'] ?? null;
            $hlcC = $cursor['hlc_c'] ?? null;

            $byAuthor[$author] = [
                is_int($hlcL) ? max(0, $hlcL) : 0,
                is_int($hlcC) ? max(0, $hlcC) : 0,
            ];
        }

        return new self($byAuthor);
    }

    /**
     * @return list<array{device_id: string, hlc_l: int, hlc_c: int}>
     */
    public function toWire(): array
    {
        $wire = [];

        foreach ($this->byAuthor as $author => [$hlcL, $hlcC]) {
            $wire[] = ['device_id' => $author, 'hlc_l' => $hlcL, 'hlc_c' => $hlcC];
        }

        return $wire;
    }

    /**
     * @return array{int, int}
     */
    public function for(string $authorDeviceId): array
    {
        return $this->byAuthor[$authorDeviceId] ?? [0, 0];
    }

    public function isEmpty(): bool
    {
        return $this->byAuthor === [];
    }
}
