<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Status;

// What a failed session says about the peer, which is not the same question as
// whether it failed. An unreachable peer is the ordinary case and the status
// surface owes it a calm answer; an identity that did not verify is not.
enum PeerFailureKind
{
    case Unreachable;

    case Verification;
}
