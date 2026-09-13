<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use LogicException;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;

// The initiator half of a LAN session, far enough to drive the responder: it
// dials the Noise IK handshake and then speaks the control vocabulary that
// LanSyncClient speaks, without the mobile module's dependency graph.
final class DiallingSyncPeer
{
    public readonly string $secretKey;

    public readonly string $publicKey;

    private NoiseHandshakeState $handshake;

    private ?NoiseSession $session = null;

    public function __construct(private readonly string $responderPublicKey)
    {
        $keypair = sodium_crypto_kx_keypair();
        $this->secretKey = sodium_crypto_kx_secretkey($keypair);
        $this->publicKey = sodium_crypto_kx_publickey($keypair);

        $this->handshake = NoiseHandshakeState::initIkInitiator(
            $this->secretKey,
            $this->publicKey,
            $this->responderPublicKey,
        );
    }

    public function publicKeyHex(): string
    {
        return sodium_bin2hex($this->publicKey);
    }

    public function handshakeMessage(): string
    {
        return $this->handshake->writeMessage('');
    }

    // Completes the handshake against the responder's reply. Split is deferred
    // to here because the transport keys do not exist until msg2 is read, which
    // is the whole reason a scripted peer needs a callback per step.
    public function adopt(string $responderReply): void
    {
        $this->handshake->readMessage($responderReply);

        [$send, $receive, $peerStatic] = $this->handshake->split();

        $this->session = new NoiseSession($send, $receive, $peerStatic);
    }

    public function session(): NoiseSession
    {
        return $this->session ?? throw new LogicException(
            'DiallingSyncPeer: the handshake has not been adopted yet.'
        );
    }

    public function encrypt(string $plaintext): string
    {
        return $this->session()->encrypt($plaintext);
    }

    /** @param array<string, mixed> $payload */
    public function encryptJson(array $payload): string
    {
        return $this->encrypt(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $ciphertext): string
    {
        return $this->session()->decrypt($ciphertext);
    }

    /** @return array<string, mixed> */
    public function decryptJson(string $ciphertext): array
    {
        $decoded = json_decode($this->decrypt($ciphertext), true, 16, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
