<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

/**
 * Cross-device pairing handshake frame types + builders (Phase 15 HIGH-01,
 * Task 2) — the two classes of message `PairingRelayCourier` carries over the
 * zero-knowledge relay so the Phase 12 both-confirm ceremony can propagate
 * between two genuinely separate physical databases.
 *
 * Mirrors the established `GDK_EPOCH_WRAP` control-message idiom (plain
 * array with a `type` key; `GdkRotationService::buildGdkEpochWrap()`) — no
 * new wire-format convention is invented here.
 *
 * NEITHER frame type carries any trust decision or secret key material:
 * `PAIR_RESPONDER_ACCEPT` carries only PUBLIC identity fields (mirrors the
 * QR payload itself); `PAIR_CONFIRM` carries only a device id pair + an
 * Ed25519 SIGNATURE (not the secret key that produced it). Every trust gate
 * lives exclusively inside `PairingTokenService::applyResponderAccept()` /
 * `applyPeerConfirm()` — this class only shapes bytes.
 */
final class PairingFrame
{
    /** Phone → desktop: binds the responder identity on the desktop's local row. */
    public const string TYPE_RESPONDER_ACCEPT = 'PAIR_RESPONDER_ACCEPT';

    /** Each → other: an Ed25519-signed confirmation of this side's safety-number match. */
    public const string TYPE_CONFIRM = 'PAIR_CONFIRM';

    /**
     * Domain-separation context prefixed onto every PAIR_CONFIRM signing
     * message (mirrors the `ElectronUpdateChannel`/op-log signing-context
     * convention) — a signature produced for this context can never be
     * replayed as valid input to a different signing domain in this
     * codebase, even if the same Ed25519 key is reused elsewhere.
     */
    public const string SIG_CONTEXT = 'beatrax-pair-confirm:v1';

    /**
     * Build the `PAIR_RESPONDER_ACCEPT` frame: the phone's responder
     * identity, addressed to the desktop so its LOCAL row can bind it and
     * advance to `awaiting_confirm`.
     *
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

    /**
     * The canonical, deterministic message a `PAIR_CONFIRM` frame's
     * signature covers. Binding both device ids + the token_hash makes a
     * signature non-replayable into any other handshake, any other pair of
     * devices, or the opposite direction of the same handshake.
     */
    public static function confirmSigningMessage(
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
    ): string {
        return self::SIG_CONTEXT.'|'.$tokenHash.'|'.$confirmingDeviceId.'|'.$peerDeviceId;
    }

    /**
     * Build the `PAIR_CONFIRM` frame: an Ed25519-signed statement that
     * $confirmingDeviceId has confirmed the safety-number match, addressed
     * to $peerDeviceId. $sigHex is a detached signature over
     * {@see self::confirmSigningMessage()} produced by the confirming
     * device's OWN Ed25519 secret key — the relay never holds that key, so
     * it cannot forge this frame (T2/T3 in the threat model).
     *
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
