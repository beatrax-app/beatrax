<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

/**
 * Noise Protocol Framework — established transport session.
 *
 * Created by NoiseHandshakeState::split() after a completed IK or XX handshake.
 * Wraps two directional NoiseCipherState instances: one for sending and one for
 * receiving. Provides an encrypt/decrypt surface for application-level transport
 * frames (OpLogEntry batches via TransportFramer).
 *
 * Direction convention (Noise §5):
 *   - Initiator: sendCipher = k1, recvCipher = k2
 *   - Responder: sendCipher = k2, recvCipher = k1
 *
 * The caller (SyncSession / WS handler) ensures that:
 *   1. All ciphertext output from encrypt() is sent before any readMessage() on the
 *      remote side, so nonce counters stay in sync.
 *   2. After a decryption failure (RuntimeException from decrypt()), the connection
 *      is closed immediately — the Noise session is non-resumable.
 *
 * peerStaticPublicKey() returns the remote device's X25519 public key (32 bytes),
 * revealed during the handshake. The caller (Plan 04 / NoisePeerAuthTest) MUST
 * verify this against DeviceRegistryService::deviceX25519Keys() before trusting
 * any frames from this session.
 *
 * This class is final but NOT readonly: the NoiseCipherState instances it holds
 * are mutable (nonce counter increments on each call).
 */
final class NoiseSession
{
    /**
     * @param  NoiseCipherState  $sendCipher  Cipher for outgoing messages (our send direction).
     * @param  NoiseCipherState  $recvCipher  Cipher for incoming messages (our receive direction).
     * @param  string  $peerStaticPublicKey  32-byte X25519 public key of the remote peer.
     */
    public function __construct(
        private readonly NoiseCipherState $sendCipher,
        private readonly NoiseCipherState $recvCipher,
        private readonly string $peerStaticPublicKey,
    ) {}

    /**
     * Encrypts $plaintext for sending to the remote peer.
     *
     * No additional data (AD) is used for transport messages — the Noise session
     * AEAD already authenticated the handshake; transport messages use empty AD
     * per the Noise spec post-split convention.
     *
     * @throws \RuntimeException on crypto failure.
     */
    public function encrypt(string $plaintext): string
    {
        return $this->sendCipher->encrypt($plaintext, '');
    }

    /**
     * Decrypts $ciphertext received from the remote peer.
     *
     * @throws \RuntimeException on AEAD authentication failure.
     *                           Caller MUST close the connection on any RuntimeException.
     */
    public function decrypt(string $ciphertext): string
    {
        return $this->recvCipher->decrypt($ciphertext, '');
    }

    /**
     * Returns the remote peer's static X25519 public key (32 bytes, raw binary).
     *
     * This key was revealed during the Noise handshake (encrypted in IK msg1/XX msg2/3).
     * The caller is responsible for verifying this key against the confirmed device
     * registry BEFORE using this session for any application data.
     *
     * Usage: hex-encode for comparison with DeviceRegistryService::deviceX25519Keys() output.
     *
     * @return string 32 bytes of raw X25519 public key material.
     */
    public function peerStaticPublicKey(): string
    {
        return $this->peerStaticPublicKey;
    }
}
