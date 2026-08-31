<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

final readonly class NoiseSession
{
    // Created by NoiseHandshakeState::split(). The caller MUST verify
    // peerStaticPublicKey() against DeviceRegistryService::deviceX25519Keys()
    // before trusting any frames from this session, and MUST close the
    // connection immediately on any decrypt() RuntimeException (non-resumable).
    /**
     * @param  NoiseCipherState  $sendCipher  Cipher for outgoing messages (our send direction).
     * @param  NoiseCipherState  $recvCipher  Cipher for incoming messages (our receive direction).
     * @param  string  $peerStaticPublicKey  32-byte X25519 public key of the remote peer.
     */
    public function __construct(
        private NoiseCipherState $sendCipher,
        private NoiseCipherState $recvCipher,
        private string $peerStaticPublicKey,
    ) {}

    // No additional data (AD) is used for transport messages — post-split,
    // the Noise spec convention is empty AD.
    /**
     * @throws \RuntimeException on crypto failure.
     */
    public function encrypt(string $plaintext): string
    {
        return $this->sendCipher->encrypt($plaintext, '');
    }

    /**
     * @throws \RuntimeException on AEAD authentication failure.
     *                           Caller MUST close the connection on any RuntimeException.
     */
    public function decrypt(string $ciphertext): string
    {
        return $this->recvCipher->decrypt($ciphertext, '');
    }

    /**
     * @return string 32 bytes of raw X25519 public key material — hex-encode
     *                for comparison with DeviceRegistryService::deviceX25519Keys().
     */
    public function peerStaticPublicKey(): string
    {
        return $this->peerStaticPublicKey;
    }
}
