<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

use SodiumException;

/**
 * Noise Protocol Framework IK and XX handshake state machine.
 *
 * Implements the two patterns used in Phase 13:
 *   - Noise_IK_25519_ChaChaPoly_BLAKE2b  (2 messages, reconnecting paired devices)
 *   - Noise_XX_25519_ChaChaPoly_BLAKE2b  (3 messages, first connect / key rotation)
 *
 * Both patterns use X25519 DH via sodium_crypto_scalarmult and BLAKE2b via
 * sodium_crypto_generichash; ChaChaPoly IETF (12-byte nonce) inside the
 * NoiseSymmetricState / NoiseCipherState.
 *
 * Static keys MUST be X25519 keypairs (sodium_crypto_kx_keypair or
 * sodium_crypto_scalarmult_base from a 32-byte secret). NOT Ed25519 keys.
 * Phase 12 stores a dedicated x25519_public_key_hex in device_registry.
 *
 * Usage (IK initiator):
 *   $h = NoiseHandshakeState::initIkInitiator($myStaticSecret, $myStaticPublic, $responderStaticPublic, $prologue);
 *   $msg1 = $h->writeMessage($payload);       // -> send to responder
 *   $h->readMessage($msg2);                   // <- receive from responder
 *   [$sendCipher, $recvCipher, $peerStatic] = $h->split();
 *
 * Usage (IK responder):
 *   $h = NoiseHandshakeState::initIkResponder($myStaticSecret, $myStaticPublic, $prologue);
 *   $payload1 = $h->readMessage($msg1);       // <- receive from initiator
 *   $msg2 = $h->writeMessage($payload);       // -> send to initiator
 *   [$sendCipher, $recvCipher, $peerStatic] = $h->split();
 *
 * For XX: use initXxInitiator / initXxResponder; 3 messages total.
 *
 * split() returns [sendCipher, recvCipher, peerStaticPublicKey]:
 *   - Initiator: sendCipher = k1 (initiator send key), recvCipher = k2 (responder send key)
 *   - Responder: sendCipher = k2 (responder send key), recvCipher = k1 (initiator send key)
 *   - peerStaticPublicKey = the remote party's revealed X25519 public key (32 bytes)
 */
final class NoiseHandshakeState
{
    // Token constants for the pattern definition
    private const TOKEN_E = 'e';

    private const TOKEN_S = 's';

    private const TOKEN_EE = 'ee';

    private const TOKEN_ES = 'es';

    private const TOKEN_SE = 'se';

    private const TOKEN_SS = 'ss';

    private const IK_PROTOCOL = 'Noise_IK_25519_ChaChaPoly_BLAKE2b';

    private const XX_PROTOCOL = 'Noise_XX_25519_ChaChaPoly_BLAKE2b';

    private NoiseSymmetricState $symmetric;

    // Local static keypair (X25519)
    private string $localStaticSecret;  // 32 bytes

    private string $localStaticPublic;  // 32 bytes

    // Local ephemeral keypair (X25519) — generated fresh per handshake
    private string $localEphemeralSecret = '';  // 32 bytes

    private string $localEphemeralPublic = '';  // 32 bytes

    // Remote keys (learned during handshake)
    private string $remoteStaticPublic = '';    // 32 bytes — filled when 's' token received

    private string $remoteEphemeralPublic = ''; // 32 bytes — filled when 'e' token received

    // Whether we are the initiator (true) or responder (false)
    private bool $isInitiator;

    // Message token sequences (remaining to process)
    /** @var list<list<string>> */
    private array $messagePatterns = [];

    // Which message we are on (index into messagePatterns)
    private int $messageIndex = 0;

    // Whether split() has been called
    private bool $splitDone = false;

    private function __construct(bool $isInitiator)
    {
        $this->isInitiator = $isInitiator;

        // Zero-init symmetric state; caller must call initProtocol().
        $this->symmetric = new NoiseSymmetricState('');
    }

    // -------------------------------------------------------------------------
    // Factory methods — IK pattern
    // -------------------------------------------------------------------------

    /**
     * Initialises the IK handshake for the INITIATOR.
     *
     * IK pre-message: <- s  (responder's static key known to initiator)
     * IK msg 1 tokens: e, es, s, ss
     * IK msg 2 tokens: e, ee, se
     *
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

        // IK pre-message: <- s (mix responder static public key into hash)
        $h->symmetric->mixHash($remoteStaticPublic);

        // IK message patterns
        $h->messagePatterns = [
            [self::TOKEN_E, self::TOKEN_ES, self::TOKEN_S, self::TOKEN_SS],   // msg 1: initiator writes
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_SE],                  // msg 2: initiator reads
        ];

        return $h;
    }

    /**
     * Initialises the IK handshake for the RESPONDER.
     *
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

        // IK pre-message: <- s (mix OUR static public key into hash, since we are the responder)
        $h->symmetric->mixHash($localStaticPublic);

        // IK message patterns
        $h->messagePatterns = [
            [self::TOKEN_E, self::TOKEN_ES, self::TOKEN_S, self::TOKEN_SS],   // msg 1: responder reads
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_SE],                  // msg 2: responder writes
        ];

        return $h;
    }

    // -------------------------------------------------------------------------
    // Factory methods — XX pattern
    // -------------------------------------------------------------------------

    /**
     * Initialises the XX handshake for the INITIATOR.
     *
     * XX msg 1 tokens: e
     * XX msg 2 tokens: e, ee, s, es
     * XX msg 3 tokens: s, se
     *
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

        // XX has no pre-messages.
        $h->messagePatterns = [
            [self::TOKEN_E],                                                       // msg 1: initiator writes
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_S, self::TOKEN_ES],       // msg 2: initiator reads
            [self::TOKEN_S, self::TOKEN_SE],                                       // msg 3: initiator writes
        ];

        return $h;
    }

    /**
     * Initialises the XX handshake for the RESPONDER.
     *
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

        // XX has no pre-messages.
        $h->messagePatterns = [
            [self::TOKEN_E],                                                       // msg 1: responder reads
            [self::TOKEN_E, self::TOKEN_EE, self::TOKEN_S, self::TOKEN_ES],       // msg 2: responder writes
            [self::TOKEN_S, self::TOKEN_SE],                                       // msg 3: responder reads
        ];

        return $h;
    }

    // -------------------------------------------------------------------------
    // Ephemeral override (for deterministic test-vector validation)
    // -------------------------------------------------------------------------

    /**
     * Injects a fixed ephemeral keypair for deterministic test-vector reproduction.
     *
     * MUST be called BEFORE the first writeMessage() or readMessage() that would
     * generate an ephemeral key. In production, never call this — let writeMessage()
     * generate fresh ephemerals via sodium_crypto_kx_keypair().
     *
     * Forward-secrecy guard (WR-01): a static/injected ephemeral destroys the
     * handshake's forward secrecy. To make this impossible to reach outside the
     * test harness, this method throws unless APP_ENV is `testing` or `local`. It
     * reads APP_ENV via getenv() (not the banned Laravel env() helper) so it stays
     * usable inside this pure-crypto class that has no container access.
     *
     * @param  string  $ephemeralSecret  32-byte X25519 secret key.
     * @param  string  $ephemeralPublic  32-byte X25519 public key.
     *
     * @throws \LogicException when called outside a testing/local environment.
     *
     * @internal Test-only seam — never call from production code paths.
     */
    public function setEphemeralKeypair(string $ephemeralSecret, string $ephemeralPublic): void
    {
        $appEnv = getenv('APP_ENV');
        if ($appEnv !== 'testing' && $appEnv !== 'local') {
            throw new \LogicException(
                'NoiseHandshakeState::setEphemeralKeypair() is a test-only seam and must '
                .'never be called outside a testing/local environment — a fixed ephemeral '
                .'destroys the handshake forward secrecy (WR-01).'
            );
        }

        $this->localEphemeralSecret = $ephemeralSecret;
        $this->localEphemeralPublic = $ephemeralPublic;
    }

    // -------------------------------------------------------------------------
    // Message send/receive
    // -------------------------------------------------------------------------

    /**
     * Writes (sends) the next handshake message, processing the current
     * token sequence and appending $payload at the end (encrypted when keyed).
     *
     * Returns the raw bytes of the message to transmit.
     *
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
     * Reads (receives) the next handshake message from the remote party.
     *
     * Returns the decrypted payload bytes from the end of the message.
     *
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

    /**
     * Returns whether the handshake is complete (all messages exchanged).
     */
    public function isComplete(): bool
    {
        return $this->messageIndex >= count($this->messagePatterns);
    }

    /**
     * Finalises the handshake and returns [sendCipher, recvCipher, peerStaticPublic].
     *
     * For the initiator:
     *   sendCipher = k1 (initiator's send key)
     *   recvCipher = k2 (responder's send key / initiator's receive key)
     *
     * For the responder:
     *   sendCipher = k2 (responder's send key)
     *   recvCipher = k1 (initiator's send key / responder's receive key)
     *
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

    // -------------------------------------------------------------------------
    // Token processing — write side
    // -------------------------------------------------------------------------

    private function processWriteToken(string $token): string
    {
        switch ($token) {
            case self::TOKEN_E:
                // Generate fresh ephemeral if not overridden by test vector injection.
                if ($this->localEphemeralPublic === '') {
                    $this->generateEphemeral();
                }
                $this->symmetric->mixHash($this->localEphemeralPublic);

                return $this->localEphemeralPublic;  // 32 bytes

            case self::TOKEN_S:
                // Encrypt-and-hash our static public key.
                return $this->symmetric->encryptAndHash($this->localStaticPublic);  // 32 or 48 bytes (32 + 16 AEAD tag)

            case self::TOKEN_EE:
                $dh = $this->dh($this->localEphemeralSecret, $this->remoteEphemeralPublic);
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return '';

            case self::TOKEN_ES:
                // Initiator: DH(local_ephemeral, remote_static)
                // Responder: DH(local_static, remote_ephemeral)  [this is the commutativity trick]
                if ($this->isInitiator) {
                    $dh = $this->dh($this->localEphemeralSecret, $this->remoteStaticPublic);
                } else {
                    $dh = $this->dh($this->localStaticSecret, $this->remoteEphemeralPublic);
                }
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return '';

            case self::TOKEN_SE:
                // Initiator: DH(local_static, remote_ephemeral)
                // Responder: DH(local_ephemeral, remote_static)
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

    // -------------------------------------------------------------------------
    // Token processing — read side
    // -------------------------------------------------------------------------

    /**
     * Processes a read token, consuming bytes from $message at $offset.
     * Returns the number of bytes consumed.
     *
     * @throws \RuntimeException on AEAD failure for 's' token.
     */
    private function processReadToken(string $token, string $message, int $offset): int
    {
        switch ($token) {
            case self::TOKEN_E:
                // Consume 32-byte remote ephemeral public key.
                $remoteEphemeral = substr($message, $offset, 32);
                $this->remoteEphemeralPublic = $remoteEphemeral;
                $this->symmetric->mixHash($remoteEphemeral);

                return 32;

            case self::TOKEN_S:
                // Decrypt-and-hash the remote static public key.
                // Before any mixKey: 32 bytes plaintext. After mixKey: 32+16 bytes (with AEAD tag).
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
                // Responder processing 'es' from msg read: DH(local_static, remote_ephemeral)
                // Initiator processing 'es' from msg read: DH(local_ephemeral, remote_static)
                if ($this->isInitiator) {
                    $dh = $this->dh($this->localEphemeralSecret, $this->remoteStaticPublic);
                } else {
                    $dh = $this->dh($this->localStaticSecret, $this->remoteEphemeralPublic);
                }
                $this->symmetric->mixKey($dh);
                sodium_memzero($dh);

                return 0;

            case self::TOKEN_SE:
                // Responder processing 'se' from msg read: DH(local_ephemeral, remote_static)
                // Initiator processing 'se' from msg read: DH(local_static, remote_ephemeral)
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Performs an X25519 DH operation: scalarmult($secretKey, $publicKey).
     *
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

    /**
     * Generates a fresh X25519 ephemeral keypair and stores it.
     *
     * Uses sodium_crypto_kx_keypair() which produces a proper X25519 keypair.
     * In test-vector mode, call setEphemeralKeypair() instead.
     */
    private function generateEphemeral(): void
    {
        $keypair = sodium_crypto_kx_keypair();
        $this->localEphemeralPublic = sodium_crypto_kx_publickey($keypair);
        $this->localEphemeralSecret = sodium_crypto_kx_secretkey($keypair);
        sodium_memzero($keypair);
    }

    /**
     * Returns true once any mixKey has run; delegates to NoiseSymmetricState::isKeyed().
     *
     * Used to size the encrypted 's' token: an unkeyed 's' is the raw 32-byte
     * public key, a keyed 's' is 32 bytes + the 16-byte AEAD tag = 48 bytes.
     */
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

    /**
     * Asserts it is the correct turn for the current party.
     *
     * In a Noise handshake, the initiator always writes odd-numbered messages
     * (index 0, 2, 4...) and the responder writes even-indexed messages (1, 3...).
     *
     * Specifically:
     * - Message index 0: initiator writes, responder reads
     * - Message index 1: responder writes, initiator reads
     * - Message index 2: initiator writes, responder reads (XX only)
     *
     * @param  bool  $write  true = asserting a write, false = asserting a read.
     */
    private function assertTurn(bool $write): void
    {
        // Even index (0, 2): initiator writes / responder reads
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
