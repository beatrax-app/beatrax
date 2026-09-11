<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Whether this device may open a LAN connection at all — a separate question
// from LanDiscoveryReach, which asks only whether the SEARCH can run. A reader
// who has not answered iOS's local-network prompt reaches a peer by no road:
// not the browse, not a typed address, not the address a scanned code carried.
/**
 * @link ../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md#the-second-gate-and-the-one-a-reader-can-open
 */
enum LocalNetworkAccess
{
    // The platform gates nothing here, or the shell answered that the reader
    // allowed it. A connection that goes unanswered is about the far side.
    case Granted;

    // The reader was asked and has not allowed it, or nothing on this device
    // can say. Both are the same advice, and neither may be stated as fact.
    case Unconfirmed;

    public function mayExplainSilence(): bool
    {
        return $this === self::Unconfirmed;
    }
}
