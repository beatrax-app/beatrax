<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Sync\Public\Services\SyncPorts;

// The ceilings every reader of a peer's self-description applies, whether the
// bytes arrived over multicast, came back from the Bonjour bridge, or were
// fetched from the peer itself. Each reader used to carry its own copy, and
// two of them said so in prose rather than in code.
final class PeerAdvertisementLimits
{
    // RFC 1035 §2.3.4: one length byte per label, so this is the protocol's
    // own ceiling and a longer label cannot be encoded at all.
    public const int MAX_LABEL_BYTES = 63;

    public const int MAX_PORT = SyncPorts::MAX;

    // What a peer may call itself. Bounded because the value reaches a log
    // line and a screen either way.
    public const int MAX_DEVICE_ID_BYTES = 128;
}
