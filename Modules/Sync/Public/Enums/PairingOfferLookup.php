<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Why a typed word code produced no initiator identity. A bare null made the
// screen tell every reader to check their network, including the one whose code
// had merely expired. The wording belongs to the surface that reports it.
enum PairingOfferLookup
{
    // A peer answered and refused it. The peer refuses an expired and an
    // unknown token identically on purpose, so this cannot tell them apart
    // either.
    case CodeNotAccepted;

    // Not a word code at all — a truncated or over-long paste. Separate from
    // the refusal above because nothing was asked of the network, so no answer
    // that mentions the network can be true of it.
    case CodeMalformed;

    // Nothing answered: no peer advertised the service, or every one that did
    // refused the connection or timed out. This is the only outcome for which
    // "check that both devices are on the same network" is true.
    case NoPeerReached;

    // A peer answered 429. Distinct from CodeNotAccepted because the advice
    // differs: "ask for a new code" cannot work here, and following it burns a
    // fresh code into the same bucket and makes the limit worse.
    case RateLimited;
}
