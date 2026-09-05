<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Whether this device's own half of a live ceremony actually left it. Both
// endings below used to be a bare `return`, which a three-second poll cannot
// tell from a frame that went out — so a phone whose identity locked kept
// drawing a live Confirm button over four minutes that sent nothing.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-ceremony-that-cannot-speak-says-so
 */
enum PairingFrameSend
{
    // Handed to a road home, or held for the peer to collect. A road that
    // refuses throws instead, so this is never a claim of delivery.
    case Sent;

    // No identity this device can open right now: the app-lock holds the KEK,
    // sync was never enabled here, or the key-file will not unseal. Which of
    // the three, and what to say about it, belongs to the surface.
    case NoUsableIdentity;

    // No row in this database names that token any more, so there is nothing
    // to address a frame from. The ceremony ended out of sight of the caller.
    case NoLocalCeremony;
}
