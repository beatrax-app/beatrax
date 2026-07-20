<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

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
        switch ($token) {
            case self::TOKEN_E:
                if ($this->localEphemeralPublic === '') {
                    $this->generateEphemeral();
                }
                $this->symmetric->mixHash($this->localEphemeralPublic);

                return $this->localEphemeralPublic;

            case self::TOKEN_S:
                return $this->symmetric->encryptAndHash($this->localStaticPublic);

            case self::TOKEN_EE:
                $dh = $this->dh($this->localEphemeralSecret, $this->remoteEphemeralPublic);
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return '';

            case self::TOKEN_ES:
                // Initiator: DH(local_ephemeral, remote_static). Responder:
                // DH(local_static, remote_ephemeral) — the commutativity trick.
                if ($this->isInitiator) {
                    $dh = $this->dh($this->localEphemeralSecret, $this->remoteStaticPublic);
                } else {
                    $dh = $this->dh($this->localStaticSecret, $this->remoteEphemeralPublic);
                }
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return '';

            case self::TOKEN_SE:
                // Initiator: DH(local_static, remote_ephemeral). Responder:
                // DH(local_ephemeral, remote_static).
                if ($this->isInitiator) {
                    $dh = $this->dh($this->localStaticSecret, $this->remoteEphemeralPublic);
                } else {
                    $dh = $this->dh($this->localEphemeralSecret, $this->remoteStaticPublic);
                }
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return '';

            case self::TOKEN_SS:
                $dh = $this->dh($this->localStaticSecret, $this->remoteStaticPublic);
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return '';

            default:
                throw new \LogicException('Noise handshake: unknown write token: '.$token);
        }
    }

    /**
     * @throws \RuntimeException on AEAD failure for 's' token.
     */
    private function processReadToken(string $token, string $message, int $offset): int
    {
        switch ($token) {
            case self::TOKEN_E:
                $remoteEphemeral = substr($message, $offset, 32);
                $this->remoteEphemeralPublic = $remoteEphemeral;
                $this->symmetric->mixHash($remoteEphemeral);

                return 32;

            case self::TOKEN_S:
                // Before any mixKey: 32 bytes plaintext. After mixKey: 32+16
                // bytes (with AEAD tag).
                $encrypted = $this->hasKey() ? substr($message, $offset, 48) : substr($message, $offset, 32);
                $remoteStatic = $this->symmetric->decryptAndHash($encrypted);
                $this->remoteStaticPublic = $remoteStatic;

                return $this->hasKey() ? 48 : 32;

            case self::TOKEN_EE:
                $dh = $this->dh($this->localEphemeralSecret, $this->remoteEphemeralPublic);
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return 0;

            case self::TOKEN_ES:
                if ($this->isInitiator) {
                    $dh = $this->dh($this->localEphemeralSecret, $this->remoteStaticPublic);
                } else {
                    $dh = $this->dh($this->localStaticSecret, $this->remoteEphemeralPublic);
                }
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return 0;

            case self::TOKEN_SE:
                if ($this->isInitiator) {
                    $dh = $this->dh($this->localStaticSecret, $this->remoteEphemeralPublic);
                } else {
                    $dh = $this->dh($this->localEphemeralSecret, $this->remoteStaticPublic);
                }
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return 0;

            case self::TOKEN_SS:
                $dh = $this->dh($this->localStaticSecret, $this->remoteStaticPublic);
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return 0;

            default:
                throw new \LogicException('Noise handshake: unknown read token: '.$token);
        }
    }

    /**
     * @throws \RuntimeException on sodium failure.
     */
    private function dh(string $secretKey, string $publicKey): string
    {
        try {
            $result = sodium_crypto_scalarmult($secretKey, $publicKey);
        } catch (SodiumException $e) {
            throw new \RuntimeException('Noise DH failed: '.$e->getMessage(), 0, $e);
        }

        return $result;
    }

    private function generateEphemeral(): void
    {
        $keypair = sodium_crypto_kx_keypair();
        $this->localEphemeralPublic = sodium_crypto_kx_publickey($keypair);
        $this->localEphemeralSecret = sodium_crypto_kx_secretkey($keypair);
        sodium_memzero($keypair);
    }

    // Sizes the encrypted 's' token: an unkeyed 's' is the raw 32-byte
    // public key, a keyed 's' is 32 bytes + the 16-byte AEAD tag = 48 bytes.
    private function hasKey(): bool
    {
        return $this->symmetric->isKeyed();
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
