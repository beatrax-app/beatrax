<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// The two frames the pre-confirmation handshake carries between devices. The
// backing values ARE the wire format — the relay has forwarded them under these
// names since it existed, and a device on an older build still sends them — so
// they are fixed, and a frame naming anything else is one this build does not
// know how to apply.
enum PairingFrameType: string
{
    // Responder -> initiator. Carries the responder's identity so the
    // initiator's local row can bind it. Makes no trust decision on its own.
    case ResponderAccept = 'PAIR_RESPONDER_ACCEPT';

    // Either side -> the other, Ed25519-signed over confirmSigningMessage().
    case Confirm = 'PAIR_CONFIRM';
}
