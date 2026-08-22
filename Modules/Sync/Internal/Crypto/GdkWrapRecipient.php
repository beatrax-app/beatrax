<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

// Who a GDK wrap is addressed to: the peer's device id and the X25519 public
// key its sealed box is minted against. One value because the two are always
// read off the same confirmed device_registry row, and a wrap sealed to one
// device's key but addressed to another's id is deliverable and unopenable.
final readonly class GdkWrapRecipient
{
    public function __construct(
        public string $deviceId,
        public string $x25519PublicKeyBin,
    ) {}
}
