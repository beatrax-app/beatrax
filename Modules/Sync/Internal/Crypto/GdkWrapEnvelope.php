<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

// A GDK_EPOCH_WRAP read off the wire and not yet judged: nothing here is
// authenticated, and $role may name a key kind this build does not know.
// GdkEpochControlHandler decides all of that afterwards, in that order.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
final readonly class GdkWrapEnvelope
{
    public function __construct(
        public int $epochId,
        public string $wrappedBin,
        public string $recipientDeviceId,
        public string $senderDeviceId,
        public string $sigHex,
        public string $role,
        public bool $senderKeyed,
    ) {}
}
