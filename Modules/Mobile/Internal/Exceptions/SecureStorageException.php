<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Exceptions;

use RuntimeException;

// Raised when on-device secure storage is reachable but its native set()
// fails: custody fails closed rather than hold the raw key in-session, where
// SESSION_DRIVER=database with session encryption off would persist the KEK
// to the sessions table in plaintext. The caller re-runs the PIN unlock path.
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class SecureStorageException extends RuntimeException
{
    public static function nativeSetFailed(string $slot): self
    {
        return new self("SecureStorageKeyCustodian: native secure-storage set() failed for slot '{$slot}'; refusing to hold the raw key in-session.");
    }
}
