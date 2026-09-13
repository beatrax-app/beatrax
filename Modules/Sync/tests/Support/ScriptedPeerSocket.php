<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\UnixAddress;
use Amp\TimeoutException;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use ArrayIterator;
use Closure;
use IteratorAggregate;
use LogicException;
use Traversable;

// A peer whose next frame may depend on what the responder has already sent —
// the Noise reply is not knowable until msg2 lands, so a fixed list cannot
// carry a whole session. Each step is a closure handed this socket, and what
// it reads back is the send log up to that point.
/**
 * @implements IteratorAggregate<int, WebsocketMessage>
 */
final class ScriptedPeerSocket implements IteratorAggregate, WebsocketClient
{
    /** @var list<string> */
    public array $sentBinary = [];

    public bool $wasClosed = false;

    public int $receives = 0;

    // How many sends land before the peer is gone. amphp raises on a send to a
    // closed connection, and a session's teardown sends after its last read.
    public int $refuseSendsAfter = PHP_INT_MAX;

    /** @var list<Closure(self): ?WebsocketMessage> */
    private array $script;

    private int $cursor = 0;

    /** @param list<Closure(self): ?WebsocketMessage> $script */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    /** @param Closure(self): ?WebsocketMessage ...$script */
    public static function running(Closure ...$script): self
    {
        return new self(array_values($script));
    }

    /** @return Closure(self): ?WebsocketMessage */
    public static function sends(string $binary): Closure
    {
        return static fn (): WebsocketMessage => WebsocketMessage::fromBinary($binary);
    }

    // What amphp really throws when a TimeoutCancellation expires: the
    // TimeoutException rides along as the previous, never as the type thrown.
    /** @return Closure(self): ?WebsocketMessage */
    public static function stalls(): Closure
    {
        return static function (): never {
            throw new CancelledException(new TimeoutException('Operation timed out'));
        };
    }

    /** @return Closure(self): ?WebsocketMessage */
    public static function hangsUp(): Closure
    {
        return static fn (): null => null;
    }

    // The frame the responder sent on its $index'th send, so a step can reply
    // to something it could not have known when the script was written.
    public function sent(int $index): string
    {
        return $this->sentBinary[$index] ?? throw new LogicException(
            'ScriptedPeerSocket: the responder has not sent frame '.$index.' yet.'
        );
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        $this->receives++;

        $step = $this->script[$this->cursor++] ?? null;

        return $step === null ? null : $step($this);
    }

    public function sendBinary(string $data): void
    {
        if (count($this->sentBinary) >= $this->refuseSendsAfter) {
            throw new ClosedException('ScriptedPeerSocket: the peer is gone.');
        }

        $this->sentBinary[] = $data;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->wasClosed = true;
    }

    public function isClosed(): bool
    {
        return $this->wasClosed;
    }

    public function getId(): int
    {
        return 1;
    }

    public function getLocalAddress(): SocketAddress
    {
        return new UnixAddress('test');
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new UnixAddress('test');
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function getCloseInfo(): WebsocketCloseInfo
    {
        throw new LogicException('ScriptedPeerSocket: close info is not modelled.');
    }

    public function isCompressionEnabled(): bool
    {
        return false;
    }

    public function sendText(string $data): void
    {
        throw new LogicException('ScriptedPeerSocket: the sync protocol is binary only.');
    }

    public function streamText(ReadableStream $stream): void
    {
        throw new LogicException('ScriptedPeerSocket: the sync protocol is binary only.');
    }

    public function streamBinary(ReadableStream $stream): void
    {
        throw new LogicException('ScriptedPeerSocket: the sync protocol never streams.');
    }

    public function ping(): void {}

    public function getCount(WebsocketCount $type): int
    {
        return 0;
    }

    public function getTimestamp(WebsocketTimestamp $type): float
    {
        return \NAN;
    }

    public function onClose(Closure $onClose): void {}

    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }
}
