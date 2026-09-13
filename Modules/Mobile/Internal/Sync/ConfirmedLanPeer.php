<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// The Noise static key of ONE named peer. Noise IK requires the initiator to
// name the responder before the first message, so a key with no device id
// beside it cannot be checked against the device the dial is aimed at — which
// is how the phone came to offer a second desktop's key to the first.
/**
 * @link ../../../../.docs/features/mobile/dialing-the-peer-this-address-belongs-to.md
 */
final readonly class ConfirmedLanPeer
{
    public function __construct(
        public string $deviceId,
        public string $staticKeyHex,
    ) {}

    // Null rather than a fallback: a device this phone holds no confirmed
    // key-agreement key for is one no IK handshake can be opened to, and
    // offering another device's key in its place reaches neither.
    /**
     * @param  array<string, string>  $confirmed  device_id => hex X25519 public key.
     */
    public static function fromConfirmed(array $confirmed, string $peerDeviceId): ?self
    {
        $keyHex = $confirmed[$peerDeviceId] ?? null;

        return is_string($keyHex) && $keyHex !== '' ? new self($peerDeviceId, $keyHex) : null;
    }
}
