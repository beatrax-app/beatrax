<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Revolt\EventLoop;
use Throwable;

// A desktop on the loopback, far enough to answer the one question Noise IK
// asks of an initiator: did you name ME? It answers the RFC6455 upgrade, reads
// the first binary frame, and tries to open it with its own static key. A
// handshake message written to another device's key does not open here, which
// is what makes "the phone dialled this desktop and offered that one's key"
// observable rather than inferred.
/**
 * @link ../../../../.docs/features/mobile/dialing-the-peer-this-address-belongs-to.md
 */
final class AnsweringNoisePeer
{
    public bool $wasDialed = false;

    public bool $openedTheHandshake = false;

    public readonly string $publicKeyHex;

    public readonly int $port;

    private readonly string $secretKey;

    private readonly string $publicKey;

    /** @var resource */
    private $server;

    private readonly string $acceptWatcher;

    /** @var list<string> */
    private array $watchers = [];

    public function __construct()
    {
        $keypair = sodium_crypto_kx_keypair();
        $this->secretKey = sodium_crypto_kx_secretkey($keypair);
        $this->publicKey = sodium_crypto_kx_publickey($keypair);
        $this->publicKeyHex = sodium_bin2hex($this->publicKey);

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new \RuntimeException('no loopback port available on this host: '.$errstr);
        }

        stream_set_blocking($server, false);
        $this->server = $server;
        $this->port = (int) explode(':', (string) stream_socket_get_name($server, false))[1];
        $this->acceptWatcher = EventLoop::onReadable($server, fn () => $this->accept());
    }

    public function stop(): void
    {
        foreach ([$this->acceptWatcher, ...$this->watchers] as $id) {
            EventLoop::cancel($id);
        }

        @fclose($this->server);
    }

    private function accept(): void
    {
        $client = @stream_socket_accept($this->server, 0);

        if ($client === false) {
            return;
        }

        $this->wasDialed = true;
        stream_set_blocking($client, false);

        $buffer = '';
        $upgraded = false;

        $this->watchers[] = EventLoop::onReadable($client, function (string $id) use ($client, &$buffer, &$upgraded): void {
            if (! $this->serve($client, $buffer, $upgraded)) {
                @fclose($client);
                EventLoop::cancel($id);
            }
        });
    }

    /**
     * @param  resource  $client
     * @return bool whether the connection is still worth watching
     */
    private function serve($client, string &$buffer, bool &$upgraded): bool
    {
        $chunk = @fread($client, 8192);

        if ($chunk === false || ($chunk === '' && feof($client))) {
            return false;
        }

        $buffer .= $chunk;

        return $upgraded
            ? $this->readHandshakeMessage($buffer)
            : $this->upgrade($client, $buffer, $upgraded);
    }

    /**
     * @param  resource  $client
     */
    private function upgrade($client, string &$buffer, bool &$upgraded): bool
    {
        if (! str_contains($buffer, "\r\n\r\n")) {
            return true;
        }

        $key = preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $buffer, $found) === 1 ? $found[1] : '';
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        @fwrite($client, sprintf(
            "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: %s\r\n\r\n",
            $accept,
        ));

        $upgraded = true;
        $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);

        return true;
    }

    // Only ever the first frame, and then the connection is done: the exchange
    // past msg1 needs a whole responder, and the question here ends at whether
    // this device could open what the initiator addressed to it.
    private function readHandshakeMessage(string &$buffer): bool
    {
        $payload = self::unmaskOneFrame($buffer);

        if ($payload === null) {
            return true;
        }

        try {
            NoiseHandshakeState::initIkResponder($this->secretKey, $this->publicKey)->readMessage($payload);
            $this->openedTheHandshake = true;
        } catch (Throwable) {
            $this->openedTheHandshake = false;
        }

        return false;
    }

    // The client masks every frame it sends, and a msg1 is 96 bytes, so only
    // the short-length form is ever seen here. Null means the frame has not
    // fully arrived yet.
    private static function unmaskOneFrame(string $buffer): ?string
    {
        $length = strlen($buffer) >= 2 ? ord($buffer[1]) & 0x7F : 0;

        if ($length >= 126 || strlen($buffer) < 6 + $length) {
            return null;
        }

        $mask = substr($buffer, 2, 4);
        $masked = substr($buffer, 6, $length);
        $plain = '';

        for ($i = 0; $i < $length; $i++) {
            $plain .= $masked[$i] ^ $mask[$i % 4];
        }

        return $plain;
    }
}
