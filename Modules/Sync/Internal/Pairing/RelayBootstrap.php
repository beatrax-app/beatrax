<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// The relay details a QR carries so a fresh responder can configure its own
// transport before the confirm handshake needs one.

// The three travel together because the last two mean nothing without the
// first: a bearer token with no endpoint is a secret with no destination, and
// a pin with no endpoint pins nothing. Keeping them in one object is what lets
// that rule live next to the values rather than in each caller.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class RelayBootstrap
{
    public function __construct(
        public ?string $endpoint = null,
        public ?string $authToken = null,
        public ?string $pin = null,
    ) {}

    // Whether there is an endpoint to advertise at all. Without one the whole
    // trio stays off the wire.
    public function isAdvertisable(): bool
    {
        return $this->endpoint !== null && $this->endpoint !== '';
    }
}
