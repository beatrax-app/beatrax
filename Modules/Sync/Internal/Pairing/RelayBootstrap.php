<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// The relay details a QR carries so a fresh responder can configure its own
// transport before the confirm handshake needs one.

// The two travel together because the second means nothing without the first:
// a pin with no endpoint pins nothing. No credential rides along — a relay-wide
// bearer in a QR is a drain credential handed to every peer that ever paired,
// and the responder mints its own per-device drain token instead.
final readonly class RelayBootstrap
{
    public function __construct(
        public ?string $endpoint = null,
        public ?string $pin = null,
    ) {}

    // Whether there is an endpoint to advertise at all. Without one the whole
    // trio stays off the wire.
    public function isAdvertisable(): bool
    {
        return $this->endpoint !== null && $this->endpoint !== '';
    }
}
