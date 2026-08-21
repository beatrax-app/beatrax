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
    // The key is in the keyring, or was already, or was deliberately not
    // adopted over a local one this device has more claim to. Terminal either
    // way: re-delivering it would change nothing.
    case Applied;

    // Valid, but this process holds no app-lock key, so the sealed box cannot
    // be opened at all. The daemon is permanently in this state. The mailbox
    // row MUST survive, or the only copy of the key is gone.
    case Deferred;

    // Malformed, forged, addressed elsewhere, or naming a role this build does
    // not know. Redelivering it would produce the same answer.
    case Refused;
}
