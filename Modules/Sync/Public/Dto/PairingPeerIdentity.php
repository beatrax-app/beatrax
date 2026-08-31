<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Dto;

// The other device's half of a pairing, as it arrives from a scanned QR or a
// fetched offer: the identity keys, and the label and address that ride with
// them. Every hop from the offer to the device_registry row carries the same
// six values, so they travel as one thing rather than as six arguments.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#why-the-responder-seeds-a-local-row
 */
final readonly class PairingPeerIdentity
{
    public function __construct(
        public string $deviceId,
        public string $ed25519PubHex,
        public string $x25519PubHex,
        // The peer's own name from its offer or accept frame. Null leaves the
        // registry's translated placeholder standing, which is the only other
        // honest answer: this device's own name is never a peer's.
        public ?string $deviceName = null,
        // Where the offer was fetched from, carried so the admit that follows
        // can hand the first sync dial an address without paying a browse.
        public ?string $lanHost = null,
        public ?int $lanPort = null,
    ) {}
}
