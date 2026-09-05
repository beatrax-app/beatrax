<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

// The authors a device asking for catch-up can verify signatures against, sent
// with the request because a sender cannot work it out: it knows which devices
// IT confirmed, which says nothing about the asker, and forwarding a third
// device's ops to a peer that revoked it spends a cursor on a refusal.
/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
final readonly class VerifiableAuthors
{
    /**
     * @param  list<string>|null  $deviceIds  Null when the peer said nothing at all.
     */
    private function __construct(public ?array $deviceIds) {}

    /**
     * @param  list<string>  $deviceIds
     */
    public static function of(array $deviceIds): self
    {
        return new self(array_values(array_unique($deviceIds)));
    }

    // A peer on a build that predates this field named no authors, which is not
    // the same as naming none: filtering it to nothing would withhold the whole
    // history from every older device in the household.
    public static function unstated(): self
    {
        return new self(null);
    }

    public static function fromWire(mixed $raw): self
    {
        if (! is_array($raw)) {
            return self::unstated();
        }

        $deviceIds = [];

        foreach ($raw as $deviceId) {
            if (is_string($deviceId) && $deviceId !== '') {
                $deviceIds[] = $deviceId;
            }
        }

        return self::of($deviceIds);
    }

    /**
     * @return list<string>|null
     */
    public function toWire(): ?array
    {
        return $this->deviceIds;
    }

    public function isStated(): bool
    {
        return $this->deviceIds !== null;
    }
}
