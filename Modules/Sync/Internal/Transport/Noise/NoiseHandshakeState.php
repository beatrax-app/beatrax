<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use SodiumException;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class NoiseHandshakeState
{
    private const TOKEN_E = 'e';

    private const TOKEN_S = 's';

    private const TOKEN_EE = 'ee';

    private const TOKEN_ES = 'es';

    private const TOKEN_SE = 'se';

    private const TOKEN_SS = 'ss';

    private const IK_PROTOCOL = 'Noise_IK_25519_ChaChaPoly_BLAKE2b';

    private const XX_PROTOCOL = 'Noise_XX_25519_ChaChaPoly_BLAKE2b';

    private const int PUBLIC_KEY_BYTES = 32;

    private const int AEAD_TAG_BYTES = 16;

    private NoiseSymmetricState $symmetric;

    /** @var string 32-byte local static X25519 secret key */
    private string $localStaticSecret;

    /** @var string 32-byte local static X25519 public key */
    private string $localStaticPublic;

    /** @var string 32-byte local ephemeral X25519 secret key, generated fresh per handshake */
    private string $localEphemeralSecret = '';

    /** @var string 32-byte local ephemeral X25519 public key, generated fresh per handshake */
    private string $localEphemeralPublic = '';

    /** @var string 32-byte remote static public key, filled when the 's' token is received */
    private string $remoteStaticPublic = '';

    /** @var string 32-byte remote ephemeral public key, filled when the 'e' token is received */
    private string $remoteEphemeralPublic = '';

    private bool $isInitiator;

    /** @var list<list<string>> Message token sequences remaining to process */
    private array $messagePatterns = [];

    private int $messageIndex = 0;

    private bool $splitDone = false;

    private function __construct(bool $isInitiator)
    {
        $this->isInitiator = $isInitiator;
        $this->symmetric = new NoiseSymmetricState('');
    }

    /**
     * @param  string  $localStaticSecret  32-byte X25519 secret key of this device.
     * @param  string  $localStaticPublic  32-byte X25519 public key of this device.
     * @param  string  $remoteStaticPublic  32-byte X25519 public key of the responder (from DeviceRegistryService).
     * @param  string  $prologue  Optional prologue bytes (mixed into handshake hash).
     */
    public static function initIkInitiator(
        string $localStaticSecret,
        string $localStaticPublic,
        string $remoteStaticPublic,
        string $prologue = '',
    ): self {
        $h = new self(true);
        $h->localStaticSecret = $localStaticSecret;
        $h->localStaticPublic = $localStaticPublic;
        $h->remoteStaticPublic = $remoteStaticPublic;

        $h->symmetric = new NoiseSymmetricState(self::IK_PROTOCOL);
        $h->symmetric->mixHash($prologue);
        $h->symmetric->mixHash($remoteStaticPublic);

        $h->messagePatterns = [
            [self::TOKEN_E, self::TOKEN_ES, self::TOKEN_S, self::TOKEN_SS],
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_SE],
        ];

        return $h;
    }

    /**
     * @param  string  $localStaticSecret  32-byte X25519 secret key of this device.
     * @param  string  $localStaticPublic  32-byte X25519 public key of this device.
     * @param  string  $prologue  Optional prologue bytes.
     */
    public static function initIkResponder(
        string $localStaticSecret,
        string $localStaticPublic,
        string $prologue = '',
    ): self {
        $h = new self(false);
        $h->localStaticSecret = $localStaticSecret;
        $h->localStaticPublic = $localStaticPublic;

        $h->symmetric = new NoiseSymmetricState(self::IK_PROTOCOL);
        $h->symmetric->mixHash($prologue);
        $h->symmetric->mixHash($localStaticPublic);

        $h->messagePatterns = [
            [self::TOKEN_E, self::TOKEN_ES, self::TOKEN_S, self::TOKEN_SS],
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_SE],
        ];

        return $h;
    }

    /**
     * @param  string  $localStaticSecret  32-byte X25519 secret key.
     * @param  string  $localStaticPublic  32-byte X25519 public key.
     * @param  string  $prologue  Optional prologue bytes.
     */
    public static function initXxInitiator(
        string $localStaticSecret,
        string $localStaticPublic,
        string $prologue = '',
    ): self {
        $h = new self(true);
        $h->localStaticSecret = $localStaticSecret;
        $h->localStaticPublic = $localStaticPublic;

        $h->symmetric = new NoiseSymmetricState(self::XX_PROTOCOL);
        $h->symmetric->mixHash($prologue);

        $h->messagePatterns = [
            [self::TOKEN_E],
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_S, self::TOKEN_ES],
            [self::TOKEN_S, self::TOKEN_SE],
        ];

        return $h;
    }

    /**
     * @param  string  $localStaticSecret  32-byte X25519 secret key.
     * @param  string  $localStaticPublic  32-byte X25519 public key.
     * @param  string  $prologue  Optional prologue bytes.
     */
    public static function initXxResponder(
        string $localStaticSecret,
        string $localStaticPublic,
        string $prologue = '',
    ): self {
        $h = new self(false);
        $h->localStaticSecret = $localStaticSecret;
        $h->localStaticPublic = $localStaticPublic;

        $h->symmetric = new NoiseSymmetricState(self::XX_PROTOCOL);
        $h->symmetric->mixHash($prologue);

        $h->messagePatterns = [
            [self::TOKEN_E],
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_S, self::TOKEN_ES],
            [self::TOKEN_S, self::TOKEN_SE],
        ];

        return $h;
    }

    // Test-only seam: injects a fixed ephemeral keypair for deterministic
    // test-vector reproduction. MUST be called BEFORE the first
    // writeMessage()/readMessage(). A static/injected ephemeral destroys
    // forward secrecy, so this throws unless APP_ENV is testing/local.
    /**
     * @param  string  $ephemeralSecret  32-byte X25519 secret key.
     * @param  string  $ephemeralPublic  32-byte X25519 public key.
     *
     * @throws \LogicException when called outside a testing/local environment.
     */
    public function setEphemeralKeypair(string $ephemeralSecret, string $ephemeralPublic): void
    {
        $appEnv = getenv('APP_ENV');
        if ($appEnv !== 'testing' && $appEnv !== 'local') {
            throw new \LogicException(
                'NoiseHandshakeState::setEphemeralKeypair() is a test-only seam and must '
                .'never be called outside a testing/local environment — a fixed ephemeral '
                .'destroys the handshake forward secrecy.'
            );
        }

        $this->localEphemeralSecret = $ephemeralSecret;
        $this->localEphemeralPublic = $ephemeralPublic;
    }

    // Processes the current token sequence and appends $payload at the end
    // (encrypted when keyed). Returns the raw bytes of the message to transmit.
    /**
     * @throws \LogicException if called when it is not this party's turn to write.
     * @throws \RuntimeException on crypto failure.
     */
    public function writeMessage(string $payload = ''): string
    {
        $this->assertNotSplit();
        $this->assertTurn(write: true);

        $tokens = $this->messagePatterns[$this->messageIndex];
        $message = '';

        foreach ($tokens as $token) {
            $message .= $this->processWriteToken($token);
        }

        $message .= $this->symmetric->encryptAndHash($payload);

        $this->messageIndex++;

        return $message;
    }

    /**
     * @throws \LogicException if called when it is not this party's turn to read.
     * @throws \RuntimeException on AEAD failure.
     */
    public function readMessage(string $message): string
    {
        $this->assertNotSplit();
        $this->assertTurn(write: false);

        $tokens = $this->messagePatterns[$this->messageIndex];
        $offset = 0;

        foreach ($tokens as $token) {
            $offset += $this->processReadToken($token, $message, $offset);
        }

        $remainder = substr($message, $offset);
        $payload = $this->symmetric->decryptAndHash($remainder);

        $this->messageIndex++;

        return $payload;
    }

    public function isComplete(): bool
    {
        return $this->messageIndex >= count($this->messagePatterns);
    }

    // Returns [sendCipher, recvCipher, peerStaticPublicKey]. For the
    // initiator, sendCipher = k1 and recvCipher = k2; for the responder,
    // sendCipher = k2 and recvCipher = k1 (see @link).
    /**
     * @return array{0: NoiseCipherState, 1: NoiseCipherState, 2: string}
     *                                                                    [sendCipher, recvCipher, peerStaticPublicKey (32 bytes)]
     *
     * @throws \LogicException if handshake is not complete yet.
     */
    public function split(): array
    {
        if (! $this->isComplete()) {
            throw new \LogicException(
                'Noise handshake: split() called before handshake is complete. '.
                'Message index: '.$this->messageIndex.' / '.count($this->messagePatterns)
            );
        }

        $this->splitDone = true;
        [$k1, $k2] = $this->symmetric->split();

        if ($this->isInitiator) {
            return [$k1, $k2, $this->remoteStaticPublic];
        }

        return [$k2, $k1, $this->remoteStaticPublic];
    }

    private function processWriteToken(string $token): string
    {
        return match ($token) {
            self::TOKEN_E => $this->emitLocalEphemeral(),
            self::TOKEN_S => $this->symmetric->encryptAndHash($this->localStaticPublic),
            self::TOKEN_EE, self::TOKEN_ES, self::TOKEN_SE, self::TOKEN_SS => $this->mixDhWrite($token),
            default => throw new \LogicException('Noise handshake: unknown write token: '.$token),
        };
    }

    /**
     * @throws \RuntimeException on AEAD failure for 's' token.
     */
    private function processReadToken(string $token, string $message, int $offset): int
    {
        return match ($token) {
            self::TOKEN_E => $this->readRemoteEphemeral($message, $offset),
            self::TOKEN_S => $this->readRemoteStatic($message, $offset),
            self::TOKEN_EE, self::TOKEN_ES, self::TOKEN_SE, self::TOKEN_SS => $this->mixDhRead($token),
            default => throw new \LogicException('Noise handshake: unknown read token: '.$token),
        };
    }

    private function emitLocalEphemeral(): string
    {
        if ($this->localEphemeralPublic === '') {
            // Generate a fresh ephemeral keypair, then zero the combined
            // keypair scratch buffer once its halves are split out.
            $keypair = sodium_crypto_kx_keypair();
            $this->localEphemeralPublic = sodium_crypto_kx_publickey($keypair);
            $this->localEphemeralSecret = sodium_crypto_kx_secretkey($keypair);
            sodium_memzero($keypair);
        }
        $this->symmetric->mixHash($this->localEphemeralPublic);

        return $this->localEphemeralPublic;
    }

    private function readRemoteEphemeral(string $message, int $offset): int
    {
        $remoteEphemeral = substr($message, $offset, self::PUBLIC_KEY_BYTES);
        $this->remoteEphemeralPublic = $remoteEphemeral;
        $this->symmetric->mixHash($remoteEphemeral);

        return self::PUBLIC_KEY_BYTES;
    }

    // Before any mixKey the 's' token is the raw public key; once keyed it
    // carries the AEAD tag as well, so both the slice length and the consumed
    // byte count switch on whether a key is already established.
    /**
     * @throws \RuntimeException on AEAD failure.
     */
    private function readRemoteStatic(string $message, int $offset): int
    {
        $length = $this->symmetric->isKeyed() ? self::PUBLIC_KEY_BYTES + self::AEAD_TAG_BYTES : self::PUBLIC_KEY_BYTES;
        $encrypted = substr($message, $offset, $length);
        $this->remoteStaticPublic = $this->symmetric->decryptAndHash($encrypted);

        return $length;
    }

    // A keyed 'ee'/'es'/'se'/'ss' token mixes a shared secret but appends no
    // bytes to the outbound message, so the write path returns an empty string.
    private function mixDhWrite(string $token): string
    {
        $this->mixKeyedDh($token);

        return '';
    }

    // The same keyed token consumes no bytes from the inbound message on the
    // read path, so the number of bytes advanced is zero.
    private function mixDhRead(string $token): int
    {
        $this->mixKeyedDh($token);

        return 0;
    }

    // Runs one keyed Diffie-Hellman token, mixing the shared secret into the
    // symmetric state and zeroing the scratch buffer after. The 'es'/'se'
    // crossover is the Noise pattern's DH commutativity: each side pairs its
    // own ephemeral against the peer's static so both derive the same secret.
    /**
     * @throws \RuntimeException on sodium failure.
     */
    private function mixKeyedDh(string $token): void
    {
        [$secret, $public] = match ($token) {
            self::TOKEN_EE => [$this->localEphemeralSecret, $this->remoteEphemeralPublic],
            self::TOKEN_ES => $this->isInitiator
                ? [$this->localEphemeralSecret, $this->remoteStaticPublic]
                : [$this->localStaticSecret, $this->remoteEphemeralPublic],
            self::TOKEN_SE => $this->isInitiator
                ? [$this->localStaticSecret, $this->remoteEphemeralPublic]
                : [$this->localEphemeralSecret, $this->remoteStaticPublic],
            self::TOKEN_SS => [$this->localStaticSecret, $this->remoteStaticPublic],
            default => throw new \LogicException('Noise handshake: unknown DH token: '.$token),
        };

        try {
            $dh = sodium_crypto_scalarmult($secret, $public);
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('Noise Diffie-Hellman', $e);
        }

        $this->symmetric->mixKey($dh);
        sodium_memzero($dh);
    }

    private function assertNotSplit(): void
    {
        if ($this->splitDone) {
            throw new \LogicException('Noise handshake: already split — cannot send/receive more messages.');
        }
    }

    // The initiator always writes even-indexed messages (0, 2, ...) and the
    // responder writes odd-indexed messages (1, 3, ...).
    private function assertTurn(bool $write): void
    {
        $initiatorTurn = ($this->messageIndex % 2 === 0);

        $writeExpected = $this->isInitiator ? $initiatorTurn : ! $initiatorTurn;

        if ($write && ! $writeExpected) {
            throw new \LogicException(
                'Noise handshake: writeMessage() called out of turn at message index '.$this->messageIndex
            );
        }

        if (! $write && $writeExpected) {
            throw new \LogicException(
                'Noise handshake: readMessage() called out of turn at message index '.$this->messageIndex
            );
        }
    }
}
