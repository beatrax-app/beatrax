<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// An address and the device expected to answer at it, carried together. Held
// apart, the phone resolved an address from one ordered read of the registry
// and a Noise static key from another unordered one, and offered one desktop's
// key to whatever answered at the other's address.
/**
 * @link ../../../../.docs/features/mobile/dialing-the-peer-this-address-belongs-to.md
 */
final readonly class PeerDial
{
    public function __construct(
        public string $deviceId,
        public string $host,
        public int $port,
    ) {}
}
