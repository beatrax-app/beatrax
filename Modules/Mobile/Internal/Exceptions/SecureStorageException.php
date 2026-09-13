<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Exceptions;

use Modules\Auth\Public\Exceptions\KeyCustodyRefused;

// Raised when on-device secure storage is reachable but its native set()
// fails: custody fails closed rather than hold the raw key in-session, where a
// persisted session would carry the KEK past the process. The caller re-runs
// the PIN unlock path.

// The shared supertype is what holds the two shells to one answer: the desktop
// custodian returned the raw key for this same refusal for a release, and a
// caller catching only this class would have kept missing it.
final class SecureStorageException extends KeyCustodyRefused
{
    public static function nativeSetFailed(string $slot): self
    {
        return new self(sprintf("SecureStorageKeyCustodian: native secure-storage set() failed for slot '%s'; refusing to hold the raw key in-session.", $slot));
    }
}
