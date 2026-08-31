<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Enums;

// How much of the PSD2 consent window is left, plus the two states that are not
// about the window at all: Off, which the connection row carries when the
// reader has the connector switched off entirely, and Revoked, which the bank
// decided and the reader has no other way to find out about.
enum ConsentStatus: string
{
    case Off = 'off';

    case Connected = 'connected';

    case Expiring = 'expiring';

    case Expired = 'expired';

    case Revoked = 'revoked';

    // Both endings stop the data flowing and both are fixed the same way, so
    // every surface that offers the reconnect path asks this rather than
    // listing the cases and forgetting one.
    public function needsReconnect(): bool
    {
        return $this === self::Expired || $this === self::Revoked;
    }
}
