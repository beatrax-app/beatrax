<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// What one LAN dial did, as a kind rather than a bool. A verification failure
// and a peer nothing answered for both used to come back false, and the one
// sentence that reading earned sent the reader to their router for a key
// problem.
/**
 * @link ../../../../.docs/features/sync/architecture.md#a-listener-without-its-identity
 */
enum LanDialOutcome
{
    case Synced;

    // Nothing answered: no route, no listener, or the OS refused the dial.
    case NotReached;

    // The peer answered and the Noise session still did not open. Reached by
    // definition — the WebSocket was up before the handshake was refused.
    case NotSecured;
}
