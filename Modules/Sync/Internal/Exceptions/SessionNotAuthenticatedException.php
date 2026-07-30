<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// A transport operation attempted before the Noise handshake established a
// key, or after the session was closed. Worth its own type because of what
// the guard prevents: op-log entries — a user's ledger — leaving the device
// unencrypted because the AEAD state was never there to encrypt them.
final class SessionNotAuthenticatedException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self("SyncSession::{$operation} — session not authenticated yet.");
    }
}
