<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// What happened to one inbound pairing frame. Each transport reads it its own
// way, which is the reason it is an outcome rather than a bool: the relay wants
// to know whether to delete the frame from its mailbox, and the LAN route wants
// to know what status to answer with.
enum PairingFrameOutcome
{
    // A redelivery of work already done lands here rather than in Refused.
    // Both roads retry, and a sender told its frame was refused for something
    // that had in fact succeeded would hold the ceremony open with nothing
    // left to do.
    case Applied;

    // A validly-signed confirm that cannot finish yet, because the human on
    // THIS device has not compared the safety words and tapped confirm. The
    // sender must send it again; it is not a failure.
    case Deferred;

    // Malformed, unknown, expired, or failed closed. Permanently rejected —
    // resending this exact frame will not change the answer.
    case Refused;
}
