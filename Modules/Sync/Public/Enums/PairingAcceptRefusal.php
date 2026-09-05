<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Why a code this device could read still produced no accepted pairing. The
// one line that names the code as unknown or expired sent readers to fetch a
// fresh one twice over a code the issuing device had just vouched for, so the
// three endings are kept apart here and each surface says the true one.
enum PairingAcceptRefusal
{
    // This device already took that code up and is waiting to confirm. Neither
    // unknown nor expired here, and the row proving it is local — nothing on
    // the wire has to be believed to know it.
    case AlreadyUnderWay;

    // The device that issued the code served its live offer for it on this very
    // submit, so "invalid or expired" is refuted by the step that got us here.
    // Whatever stopped the accept was on this side.
    case VouchedByIssuer;

    // Nothing observed says the code is live: an unreadable payload, a code no
    // peer would answer for, or a ceremony whose row has ended. The only ending
    // that may call a code unknown or expired.
    case NotLiveHere;
}
