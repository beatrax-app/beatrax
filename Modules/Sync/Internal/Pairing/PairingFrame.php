<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

final class PairingFrame
{
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
        string $responderName = '',
    ): array {
        return [
            'type' => PairingFrameType::ResponderAccept->value,
            'token_hash' => $tokenHash,
            'responder_device_id' => $responderDeviceId,
            'responder_ed25519_pub_hex' => $responderEd25519Hex,
            'responder_x25519_pub_hex' => $responderX25519Hex,
            // Cosmetic only — a label for the device list. It is NOT part of
            // the signed confirm message and grants nothing, so a wrong name
            // is a wrong caption and never a trust decision.
            'responder_name' => $responderName,
        ];
    }

    // The deterministic message a PAIR_CONFIRM signature covers: token_hash +
    // both device ids + both X25519 sealing keys, ordered (confirming, peer).
    // Binding the sealing keys ties them to the safety-number-verified Ed25519
    // identity, so a relay-swapped accept-frame X25519 fails this signature.
    public static function confirmSigningMessage(
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $confirmingDeviceX25519Hex,
        string $peerDeviceX25519Hex,
    ): string {
        // Length-prefix each field so a value containing the '|' delimiter
        // cannot shift field boundaries in the signed string.
        return implode('|', [
            self::SIG_CONTEXT,
            strlen($tokenHash).':'.$tokenHash,
            strlen($confirmingDeviceId).':'.$confirmingDeviceId,
            strlen($peerDeviceId).':'.$peerDeviceId,
            strlen($confirmingDeviceX25519Hex).':'.$confirmingDeviceX25519Hex,
            strlen($peerDeviceX25519Hex).':'.$peerDeviceX25519Hex,
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
            'type' => PairingFrameType::Confirm->value,
            'token_hash' => $tokenHash,
            'confirming_device_id' => $confirmingDeviceId,
            'peer_device_id' => $peerDeviceId,
            'sig_hex' => $sigHex,
        ];
    }
}
