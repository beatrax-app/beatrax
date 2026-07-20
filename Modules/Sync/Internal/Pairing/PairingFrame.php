<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class PairingFrame
{
    public const string TYPE_RESPONDER_ACCEPT = 'PAIR_RESPONDER_ACCEPT';

    public const string TYPE_CONFIRM = 'PAIR_CONFIRM';

    // Domain-separation context prefixed onto every PAIR_CONFIRM signing
    // message — a signature produced for this context can never be replayed
    // as valid input to a different signing domain in this codebase, even
    // if the same Ed25519 key is reused elsewhere.
    public const string SIG_CONTEXT = 'beatrax-pair-confirm:v1';

    /**
     * @return array{type: string, token_hash: string, responder_device_id: string, responder_ed25519_pub_hex: string, responder_x25519_pub_hex: string}
     */
    public static function buildResponderAccept(
        string $tokenHash,
        string $responderDeviceId,
        string $responderEd25519Hex,
        string $responderX25519Hex,
    ): array {
        return [
            'type' => self::TYPE_RESPONDER_ACCEPT,
            'token_hash' => $tokenHash,
            'responder_device_id' => $responderDeviceId,
            'responder_ed25519_pub_hex' => $responderEd25519Hex,
            'responder_x25519_pub_hex' => $responderX25519Hex,
        ];
    }

    // The canonical, deterministic message a PAIR_CONFIRM frame's signature
    // covers. Binding both device ids + the token_hash makes a signature
    // non-replayable into any other handshake, any other pair of devices,
    // or the opposite direction of the same handshake.
    public static function confirmSigningMessage(
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
    ): string {
        // Length-prefix each field so a device id containing the '|'
        // delimiter cannot shift field boundaries in the signed string.
        return implode('|', [
            self::SIG_CONTEXT,
            strlen($tokenHash).':'.$tokenHash,
            strlen($confirmingDeviceId).':'.$confirmingDeviceId,
            strlen($peerDeviceId).':'.$peerDeviceId,
        ]);
    }

    // $sigHex is a detached signature over confirmSigningMessage() produced
    // by the confirming device's OWN Ed25519 secret key — the relay never
    // holds that key, so it cannot forge this frame.
    /**
     * @return array{type: string, token_hash: string, confirming_device_id: string, peer_device_id: string, sig_hex: string}
     */
    public static function buildConfirm(
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $sigHex,
    ): array {
        return [
            'type' => self::TYPE_CONFIRM,
            'token_hash' => $tokenHash,
            'confirming_device_id' => $confirmingDeviceId,
            'peer_device_id' => $peerDeviceId,
            'sig_hex' => $sigHex,
        ];
    }
}
