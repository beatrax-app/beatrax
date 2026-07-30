<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// The peer went away mid-handshake, before the transport had a session to
// close cleanly. Separated from the Noise failures because nothing
// cryptographic went wrong: there is simply nobody left to talk to, and the
// caller's job is to tear down rather than to distrust anything.
final class PeerDisconnectedException extends RuntimeException
{
    public static function beforeHandshakeMessage(string $message): self
    {
        return new self("Sync peer disconnected before sending Noise {$message}.");
    }
}
