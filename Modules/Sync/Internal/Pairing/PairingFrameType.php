<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// The backing values ARE the wire format: the relay has forwarded them under
// these names since it existed and older builds still send them, so they are
// fixed.
enum PairingFrameType: string
{
    // Responder -> initiator. Carries the responder's identity so the
    // initiator's local row can bind it. Makes no trust decision on its own.
    case ResponderAccept = 'PAIR_RESPONDER_ACCEPT';

    // Either side -> the other, Ed25519-signed over confirmSigningMessage().
    case Confirm = 'PAIR_CONFIRM';
}
