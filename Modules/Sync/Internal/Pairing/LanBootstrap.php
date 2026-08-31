<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// Where a QR says this device can be reached directly, so a responder that
// cannot browse the network still has somewhere to send its accept. The relay
// arm answers the same question through an intermediary; a phone needs one of
// the two, because iOS grants no multicast entitlement (see @link).
/**
 * @link ../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
final readonly class LanBootstrap
{
    public function __construct(
        public ?string $host = null,
        public ?int $port = null,
    ) {}

    // Both halves or neither: a host with no port names no listener, and a
    // port with no host names no machine.
    public function isAdvertisable(): bool
    {
        return $this->host !== null && $this->host !== ''
            && $this->port !== null && $this->port > 0;
    }
}
