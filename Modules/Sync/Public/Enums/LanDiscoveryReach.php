<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Whether this device can put a discovery question on the network at all. An
// empty browse used to carry both "nobody answered" and "this platform cannot
// ask", so a screen reporting the silence had to guess which — and on an
// iPhone, which never asks, it guessed wrong and blamed the reader's router.
/**
 * @link ../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
enum LanDiscoveryReach
{
    // The question reached the network. Nothing coming back is an answer:
    // no peer is advertising the service here.
    case Available;

    // The question never left this device, so nothing coming back is not an
    // answer about the network — it is the absence of one.
    case Unsupported;

    public function silenceMeansNoPeers(): bool
    {
        return $this === self::Available;
    }
}
