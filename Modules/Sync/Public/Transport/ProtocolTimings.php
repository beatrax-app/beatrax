<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Transport;

use Modules\Core\Public\Enums\Duration;

// Every bound the LAN sync protocol waits on. The initiator and the responder
// are separate classes in separate modules, so a bound written twice is a
// phase one half abandons while the other is still working — which is what a
// fifteen-second initiator against a sixty-second responder already was.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
final class ProtocolTimings
{
    // Bounds the pre-auth slow-loris window: a peer that connects and never
    // sends msg1 is dropped rather than parking a fiber forever.
    public const float HANDSHAKE_SECONDS = 10.0;

    // The sync dial. Longer than the pairing probe below because the first LAN
    // connect per install can sit behind the iOS Local Network Privacy prompt,
    // which a one-second bound turns into a hard failure on the one dial that
    // has to succeed for a phone to sync at all.
    public const float SYNC_DIAL_SECONDS = 5.0;

    // The pairing browse dials up to four candidates in sequence while a
    // reader watches a spinner, so it trades the prompt above for a bound that
    // gets through the list. Not the same number as the sync dial, on purpose.
    public const int PAIRING_PROBE_CONNECT_SECONDS = 1;

    public const int PAIRING_PROBE_REQUEST_SECONDS = 2;

    // Long enough for a desktop on the same subnet to answer, short enough
    // that a phone with nothing to find says so rather than hanging.
    public const float BROWSE_SECONDS = 2.0;

    // Shorter than the three-second poll that drives it, so a poll's three
    // browses share one answer and the next poll still asks again. Measured
    // from when the answer landed, not from when the browse was asked.
    public const float DISCOVERY_CACHE_TTL_SECONDS = 2.5;

    // How long the responder may spend producing a large replay batch for a
    // peer that has gone quiet before it drops the connection.
    public static function responderReadSeconds(): float
    {
        return (float) Duration::Minute->seconds();
    }

    // Derived from the responder's rather than restated: an initiator bound
    // below what the responder is allowed to spend abandons a batch the
    // responder is still sending, on every sync large enough to matter.
    public static function initiatorReadSeconds(): float
    {
        return self::responderReadSeconds();
    }
}
