<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Why a typed word code did not produce an initiator identity. The lookup used
// to answer this question with a bare null, so the one screen that asks it told
// every reader to go and check their network — including the reader whose
// network was fine and whose code had simply expired.
//
// The wording belongs to the surface that reports it, not here: this names what
// happened, and each client decides how to say it.
enum PairingOfferLookup
{
    // The code is not a word code at all, or a peer answered and would not hand
    // over an offer for it. Both mean the same thing to the reader — this code
    // did not work — and the second deliberately does not distinguish an expired
    // token from an unknown one, because the peer refuses both identically on
    // purpose so that probing the endpoint learns nothing.
    case CodeNotAccepted;

    // Nothing answered: no peer advertised the service, or every one that did
    // refused the connection or timed out. This is the only outcome for which
    // "check that both devices are on the same network" is true.
    case NoPeerReached;
}
