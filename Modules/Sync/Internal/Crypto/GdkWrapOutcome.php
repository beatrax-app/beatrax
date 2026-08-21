<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

// What happened to one inbound GDK wrap. An outcome rather than a bool, and
// rather than an exception, because the drain that consumes these needs to tell
// "this key is now mine" from "I could not open it yet" — and the handler is
// contractually forbidden from throwing, so an exception cannot carry it.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
enum GdkWrapOutcome
{
    // The key is in the keyring, or was already. Terminal: re-delivering it
    // would change nothing, and the carrier may be consumed.
    case Applied;

    // Valid, but this process cannot decide yet — no app-lock key, a sender
    // not confirmed at this instant, an envelope a later build may understand.
    // The carrier MUST survive, or the only copy of the key is gone.
    case Deferred;

    // A decision was reached and the peer's key was deliberately NOT adopted,
    // over a local key this device has more claim to. The carrier still holds
    // the only copy of the peer's index, which is what a re-derivation would
    // need, so it survives to its expiry rather than being consumed.
    case Retained;

    // Provably invalid for this device however often it is redelivered:
    // addressed elsewhere, signed by nobody this device trusts, or opened and
    // failed. The carrier may be consumed.
    case Refused;

    // Whether the transport that carried this wrap may retire its copy. The
    // two keep-cases differ in what they mean and not in what the drain does,
    // so the drain asks this rather than restating the split.
    public function consumesCarrier(): bool
    {
        return $this === self::Applied || $this === self::Refused;
    }
}
