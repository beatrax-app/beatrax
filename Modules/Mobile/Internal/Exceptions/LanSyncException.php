<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Exceptions;

use RuntimeException;

// Raised by the LAN sync client for the failures it cannot model as a plain
// false: the desktop peer failing the confirmed-device auth gate (a
// security-relevant rejection, never a transient), the peer revoking this
// device, and the peer vanishing mid-handshake before a Noise session exists.
final class LanSyncException extends RuntimeException
{
    // Every instance of this class is the same class, so a log line naming it
    // told a reader of the log nothing at all: a peer that went to sleep and a
    // peer that revoked this device arrived as one indistinguishable line.
    public const string REASON_GATE_REFUSED = 'gate_refused';

    public const string REASON_PEER_REVOKED = 'peer_revoked';

    public const string REASON_DIAL_INCOMPLETE = 'dial_incomplete';

    private bool $peerRevocation = false;

    private bool $dialIncomplete = false;

    public static function peerFailedConfirmedDeviceGate(): self
    {
        return new self('LanSyncClient: desktop peer failed the confirmed-device auth gate.');
    }

    // Which of the three this is, as a value a log line can carry. The two
    // predicates below answer what the CLIENT must do; this answers what
    // happened, which is the question anyone reading the log afterwards has.
    public function reason(): string
    {
        return match (true) {
            $this->peerRevocation => self::REASON_PEER_REVOKED,
            $this->dialIncomplete => self::REASON_DIAL_INCOMPLETE,
            default => self::REASON_GATE_REFUSED,
        };
    }

    // The peer told us, over its authenticated Noise session, that it no
    // longer confirms this device. Distinct from every other failure here
    // because retrying is pointless — the trust is gone, not the network.
    public static function peerRevokedThisDevice(): self
    {
        $instance = new self('LanSyncClient: the peer no longer confirms this device.');
        $instance->peerRevocation = true;

        return $instance;
    }

    public function isPeerRevocation(): bool
    {
        return $this->peerRevocation;
    }

    // A peer that goes away before the handshake finishes is the dial not
    // completing — it slept, or left the network. Marked so the client can
    // treat it like the timeout it is, rather than raising it at the reader.
    public static function peerDisconnectedBeforeHandshakeMessage(string $message): self
    {
        $instance = new self("LanSyncClient: peer disconnected before sending Noise {$message}.");
        $instance->dialIncomplete = true;

        return $instance;
    }

    public function isDialIncomplete(): bool
    {
        return $this->dialIncomplete;
    }
}
