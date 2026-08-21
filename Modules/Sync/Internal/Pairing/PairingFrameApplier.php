<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// The one place an inbound pairing frame is applied, whichever road it arrived
// by: the idempotency and fail-closed rules here are the handshake's whole
// security posture, and two transports with a copy each would drift apart.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairingFrameApplier
{
    public function __construct(private PairingTokenService $tokenService) {}

    /**
     * @param  array<string, mixed>  $frame
     */
    public function apply(int $userId, array $frame): PairingFrameOutcome
    {
        $type = isset($frame['type']) && is_string($frame['type'])
            ? PairingFrameType::tryFrom($frame['type'])
            : null;

        return match ($type) {
            PairingFrameType::ResponderAccept => $this->applyResponderAccept($userId, $frame),
            PairingFrameType::Confirm => $this->applyConfirm($userId, $frame),
            // A frame this build does not know how to apply. Refusing it is
            // terminal for the sender and tells a prober nothing.
            null => PairingFrameOutcome::Refused,
        };
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function applyResponderAccept(int $userId, array $frame): PairingFrameOutcome
    {
        $tokenHash = self::stringField($frame, 'token_hash');
        $responderDeviceId = self::stringField($frame, 'responder_device_id');
        $responderEd = self::stringField($frame, 'responder_ed25519_pub_hex');
        $responderKx = self::stringField($frame, 'responder_x25519_pub_hex');

        if ($tokenHash === null || $responderDeviceId === null || $responderEd === null || $responderKx === null) {
            return PairingFrameOutcome::Refused;
        }

        // The device id arrives from an untrusted frame. Reject anything that
        // is not a UUIDv4 (DeviceIdentityService's format) before it is
        // persisted or signed over.
        if (! self::isValidDeviceId($responderDeviceId)) {
            return PairingFrameOutcome::Refused;
        }

        // applyResponderAccept() never defers — it either binds (or
        // idempotently no-ops) or fails closed.
        $bound = $this->tokenService->applyResponderAccept(
            $userId,
            $tokenHash,
            $responderDeviceId,
            $responderEd,
            $responderKx,
            self::stringField($frame, 'responder_name') ?? '',
        );

        return $bound === false ? PairingFrameOutcome::Refused : PairingFrameOutcome::Applied;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function applyConfirm(int $userId, array $frame): PairingFrameOutcome
    {
        $tokenHash = self::stringField($frame, 'token_hash');
        $confirmingDeviceId = self::stringField($frame, 'confirming_device_id');
        $peerDeviceId = self::stringField($frame, 'peer_device_id');
        $sigHex = self::stringField($frame, 'sig_hex');

        if ($tokenHash === null || $confirmingDeviceId === null || $peerDeviceId === null || $sigHex === null) {
            return PairingFrameOutcome::Refused;
        }

        if (! self::isValidDeviceId($confirmingDeviceId) || ! self::isValidDeviceId($peerDeviceId)) {
            return PairingFrameOutcome::Refused;
        }

        $result = $this->tokenService->applyPeerConfirm($userId, $tokenHash, $confirmingDeviceId, $peerDeviceId, $sigHex);

        return match ($result) {
            null => PairingFrameOutcome::Refused,
            'deferred' => PairingFrameOutcome::Deferred,
            default => PairingFrameOutcome::Applied,
        };
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private static function stringField(array $frame, string $key): ?string
    {
        return isset($frame[$key]) && is_string($frame[$key]) ? $frame[$key] : null;
    }

    // A device id from an untrusted frame is only accepted when it is a
    // UUIDv4 — the exact shape DeviceIdentityService mints. This bounds length
    // and charset and structurally excludes the '|' signing-message delimiter.
    private static function isValidDeviceId(string $deviceId): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $deviceId,
        ) === 1;
    }
}
