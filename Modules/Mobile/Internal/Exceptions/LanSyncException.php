<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Exceptions;

use RuntimeException;

// Raised by the LAN sync client for the two failures it cannot model as a
// retryable outcome: the desktop peer failing the confirmed-device auth
// gate (a security-relevant rejection, never a transient), and the peer
// vanishing mid-handshake before a Noise session exists to close.
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class LanSyncException extends RuntimeException
{
    public static function peerFailedConfirmedDeviceGate(): self
    {
        return new self('LanSyncClient: desktop peer failed the confirmed-device auth gate (T-13-13).');
    }

    public static function peerDisconnectedBeforeHandshakeMessage(string $message): self
    {
        return new self("LanSyncClient: peer disconnected before sending Noise {$message}.");
    }
}
