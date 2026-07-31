<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Console\ServeOpenBankingTlsCommand;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/*
 * The proxy loop's pump/relay/select helpers, driven directly over real
 * in-process socket pairs.
 *
 * The command's handle() body is a blocking single-threaded socket proxy: it
 * accepts on a TLS listener, opens an upstream per client, and pumps bytes
 * between the two until a signal arrives. Running the whole loop against real
 * ports would race a proxy against itself and fail for reasons unrelated to the
 * code under test — so instead each helper is exercised in isolation with
 * stream_socket_pair() sockets and a throwaway loopback listener. These are the
 * per-tick decisions (what is readable, which halves to flush, when a peer has
 * hung up) that the loop is made of.
 */

/**
 * @return array{0: ServeOpenBankingTlsCommand, 1: string} the command and the
 *                                                         throwaway cert dir to clean up
 */
function tlsPumpCommand(): array
{
    $dir = sys_get_temp_dir().'/ob-tls-pump-'.bin2hex(random_bytes(6));
    $command = new ServeOpenBankingTlsCommand(
        app(LoopbackRedirectUri::class),
        new LoopbackTlsCertificate($dir),
    );

    return [$command, $dir];
}

function tlsPumpMethod(string $name): ReflectionMethod
{
    $method = new ReflectionMethod(ServeOpenBankingTlsCommand::class, $name);
    $method->setAccessible(true);

    return $method;
}

/**
 * @return array{0: resource, 1: resource} a connected, non-blocking socket pair
 */
function tlsSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pair)->not->toBeFalse();
    stream_set_blocking($pair[0], false);
    stream_set_blocking($pair[1], false);

    return $pair;
}

beforeEach(function (): void {
    [$this->tlsCommand, $this->tlsCertDir] = tlsPumpCommand();
    $this->tlsCloseables = [];
});

afterEach(function (): void {
    foreach ($this->tlsCloseables as $resource) {
        if (is_resource($resource)) {
            @fclose($resource);
        }
    }
    if (is_dir($this->tlsCertDir)) {
        foreach (glob($this->tlsCertDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tlsCertDir);
    }
});

it('relay forwards a client read to its peer and flushes it through', function (): void {
    [$clientNear, $clientFar] = tlsSocketPair();
    [$upstreamNear, $upstreamFar] = tlsSocketPair();
    $this->tlsCloseables = [$clientNear, $clientFar, $upstreamNear, $upstreamFar];

    $clientId = (int) $clientNear;
    $upstreamId = (int) $upstreamNear;
    $conns = [
        $clientId => ['sock' => $clientNear, 'peer' => $upstreamId, 'wbuf' => ''],
        $upstreamId => ['sock' => $upstreamNear, 'peer' => $clientId, 'wbuf' => ''],
    ];

    // A client byte arrives; relay() must land it on the upstream half.
    fwrite($clientFar, 'GET / HTTP/1.1');

    $args = [&$conns, $clientNear];
    tlsPumpMethod('relay')->invokeArgs($this->tlsCommand, $args);

    expect(fread($upstreamFar, 4096))->toBe('GET / HTTP/1.1');
    // Fully flushed — nothing left buffered on the upstream half.
    expect($conns[$upstreamId]['wbuf'])->toBe('');
});

it('relay closes the whole pair when the socket has reached EOF', function (): void {
    [$clientNear, $clientFar] = tlsSocketPair();
    [$upstreamNear, $upstreamFar] = tlsSocketPair();
    $this->tlsCloseables = [$clientNear, $upstreamNear, $upstreamFar];

    $clientId = (int) $clientNear;
    $upstreamId = (int) $upstreamNear;
    $conns = [
        $clientId => ['sock' => $clientNear, 'peer' => $upstreamId, 'wbuf' => ''],
        $upstreamId => ['sock' => $upstreamNear, 'peer' => $clientId, 'wbuf' => ''],
    ];

    // The client hangs up: the near end now reads '' at EOF, so the pair is torn
    // down on both halves.
    fclose($clientFar);

    $args = [&$conns, $clientNear];
    tlsPumpMethod('relay')->invokeArgs($this->tlsCommand, $args);

    expect($conns)->not->toHaveKey($clientId)
        ->and($conns)->not->toHaveKey($upstreamId);
});

it('relay ignores a socket it is not tracking', function (): void {
    [$stray] = tlsSocketPair();
    $this->tlsCloseables = [$stray];

    $conns = [];
    $args = [&$conns, $stray];
    tlsPumpMethod('relay')->invokeArgs($this->tlsCommand, $args);

    expect($conns)->toBe([]);
});

it('relay leaves the pair intact when a readable socket yields no bytes yet', function (): void {
    // A non-blocking socket whose peer is still open but has written nothing:
    // fread() returns '' with feof() false, so relay() must simply return
    // without tearing anything down.
    [$clientNear, $clientFar] = tlsSocketPair();
    [$upstreamNear, $upstreamFar] = tlsSocketPair();
    $this->tlsCloseables = [$clientNear, $clientFar, $upstreamNear, $upstreamFar];

    $clientId = (int) $clientNear;
    $upstreamId = (int) $upstreamNear;
    $conns = [
        $clientId => ['sock' => $clientNear, 'peer' => $upstreamId, 'wbuf' => ''],
        $upstreamId => ['sock' => $upstreamNear, 'peer' => $clientId, 'wbuf' => ''],
    ];

    $args = [&$conns, $clientNear];
    tlsPumpMethod('relay')->invokeArgs($this->tlsCommand, $args);

    expect($conns)->toHaveCount(2)
        ->and($conns[$upstreamId]['wbuf'])->toBe('');
});

it('closeAll tears down every tracked pair', function (): void {
    [$clientNear, $clientFar] = tlsSocketPair();
    [$upstreamNear, $upstreamFar] = tlsSocketPair();
    $this->tlsCloseables = [$clientFar, $upstreamFar];

    $clientId = (int) $clientNear;
    $upstreamId = (int) $upstreamNear;
    $conns = [
        $clientId => ['sock' => $clientNear, 'peer' => $upstreamId, 'wbuf' => ''],
        $upstreamId => ['sock' => $upstreamNear, 'peer' => $clientId, 'wbuf' => ''],
    ];

    $args = [&$conns];
    tlsPumpMethod('closeAll')->invokeArgs($this->tlsCommand, $args);

    expect($conns)->toBe([]);
});

it('pumpWritable flushes only the buffered halves it is handed', function (): void {
    [$upstreamNear, $upstreamFar] = tlsSocketPair();
    [$strayNear] = tlsSocketPair();
    $this->tlsCloseables = [$upstreamNear, $upstreamFar, $strayNear];

    $upstreamId = (int) $upstreamNear;
    $conns = [
        $upstreamId => ['sock' => $upstreamNear, 'peer' => 999, 'wbuf' => 'pending-bytes'],
    ];

    // The write set names the buffered half plus a socket that is not tracked;
    // the untracked one must be skipped without error.
    $write = [$upstreamNear, $strayNear];
    $args = [&$conns, $write];
    tlsPumpMethod('pumpWritable')->invokeArgs($this->tlsCommand, $args);

    expect(fread($upstreamFar, 4096))->toBe('pending-bytes')
        ->and($conns[$upstreamId]['wbuf'])->toBe('');
});

it('pumpReadable accepts a pending client on the server and relays a tracked socket', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $serverAddress = stream_socket_get_name($server, false);

    // A real pending connection so accept() returns immediately rather than
    // blocking on its handshake timeout.
    $client = @stream_socket_client('tcp://'.$serverAddress, $errno, $errstr, 1);
    expect($client)->not->toBeFalse();

    [$trackedNear, $trackedFar] = tlsSocketPair();
    [$peerNear, $peerFar] = tlsSocketPair();
    $this->tlsCloseables = [$server, $client, $trackedNear, $trackedFar, $peerNear, $peerFar];

    $trackedId = (int) $trackedNear;
    $peerId = (int) $peerNear;
    $conns = [
        $trackedId => ['sock' => $trackedNear, 'peer' => $peerId, 'wbuf' => ''],
        $peerId => ['sock' => $peerNear, 'peer' => $trackedId, 'wbuf' => ''],
    ];
    fwrite($trackedFar, 'relayed');

    // A backend port nothing is listening on: accept() will take the pending
    // client, fail to reach the upstream, and drop it — the branch that proves
    // the server arm ran without leaving a half-open connection tracked.
    $deadBackendPort = 1;

    $read = [$server, $trackedNear];
    $args = [$server, $deadBackendPort, &$conns, $read];
    tlsPumpMethod('pumpReadable')->invokeArgs($this->tlsCommand, $args);

    // The tracked read was relayed onto its peer.
    expect(fread($peerFar, 4096))->toBe('relayed');
    // The accepted client could not reach the dead backend, so no new pair was
    // registered — only the two tracked sockets remain.
    expect($conns)->toHaveCount(2);
});

it('selectSockets reports nothing ready on an idle tick', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $this->tlsCloseables = [$server];

    $conns = [];
    [$readable, $writable] = tlsPumpMethod('selectSockets')->invoke($this->tlsCommand, $server, $conns);

    expect($readable)->toBeNull()
        ->and($writable)->toBe([]);
});

it('selectSockets surfaces the ready read set and the buffered write set', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    [$readableNear, $readableFar] = tlsSocketPair();
    [$bufferedNear] = tlsSocketPair();
    $this->tlsCloseables = [$server, $readableNear, $readableFar, $bufferedNear];

    $readableId = (int) $readableNear;
    $bufferedId = (int) $bufferedNear;
    $conns = [
        // Readable this tick (data waiting), no pending write.
        $readableId => ['sock' => $readableNear, 'peer' => $bufferedId, 'wbuf' => ''],
        // Has bytes queued to write, so it must appear in the write set.
        $bufferedId => ['sock' => $bufferedNear, 'peer' => $readableId, 'wbuf' => 'queued'],
    ];
    fwrite($readableFar, 'incoming');

    [$readSet, $writeSet] = tlsPumpMethod('selectSockets')->invoke($this->tlsCommand, $server, $conns);

    expect($readSet)->not->toBeNull()
        ->and($readSet)->toContain($readableNear)
        ->and($writeSet)->toContain($bufferedNear);
});

/**
 * Drives the command's private `running` flag; the loop reads it every tick and
 * there is no public setter outside the signal handler.
 */
function tlsSetRunning(ServeOpenBankingTlsCommand $command, bool $running): void
{
    $property = new ReflectionProperty(ServeOpenBankingTlsCommand::class, 'running');
    $property->setAccessible(true);
    $property->setValue($command, $running);
}

/**
 * Gives the command a throwaway output sink so its newLine()/error() calls have
 * somewhere to write when the loop is exercised outside a real console run.
 */
function tlsSilenceOutput(ServeOpenBankingTlsCommand $command): void
{
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
}

it('accept pairs the client with a reachable backend and tracks both halves', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $backend = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse()->and($backend)->not->toBeFalse();
    $backendPort = (int) explode(':', stream_socket_get_name($backend, false))[1];

    $client = @stream_socket_client('tcp://'.stream_socket_get_name($server, false), $errno, $errstr, 1);
    expect($client)->not->toBeFalse();
    $this->tlsCloseables = [$server, $backend, $client];

    $conns = [];
    $args = [$server, $backendPort, &$conns];
    tlsPumpMethod('accept')->invokeArgs($this->tlsCommand, $args);

    // The accepted client and its freshly dialled upstream are both registered.
    expect($conns)->toHaveCount(2);
    foreach ($conns as $conn) {
        $this->tlsCloseables[] = $conn['sock'];
    }
});

it('flush is a no-op for an untracked or empty half', function (): void {
    $conns = [];
    $args = [&$conns, 123];
    tlsPumpMethod('flush')->invokeArgs($this->tlsCommand, $args);

    expect($conns)->toBe([]);
});

it('flush drains the buffered bytes onto the socket', function (): void {
    [$near, $far] = tlsSocketPair();
    $this->tlsCloseables = [$near, $far];

    $id = (int) $near;
    $conns = [$id => ['sock' => $near, 'peer' => 999, 'wbuf' => 'payload']];
    $args = [&$conns, $id];
    tlsPumpMethod('flush')->invokeArgs($this->tlsCommand, $args);

    expect(fread($far, 4096))->toBe('payload')
        ->and($conns[$id]['wbuf'])->toBe('');
});

it('flush tears the pair down when the socket write fails', function (): void {
    // A read-only handle is an open resource whose fwrite() returns false, so it
    // trips the write-failure branch without the TypeError a closed resource
    // would raise.
    $readOnly = fopen('php://temp', 'r');
    [$peerNear, $peerFar] = tlsSocketPair();
    $this->tlsCloseables = [$readOnly, $peerNear, $peerFar];

    $id = (int) $readOnly;
    $peerId = (int) $peerNear;
    $conns = [
        $id => ['sock' => $readOnly, 'peer' => $peerId, 'wbuf' => 'payload'],
        $peerId => ['sock' => $peerNear, 'peer' => $id, 'wbuf' => ''],
    ];

    $args = [&$conns, $id];
    tlsPumpMethod('flush')->invokeArgs($this->tlsCommand, $args);

    expect($conns)->toBe([]);
});

it('closePair flushes the peer buffer before closing both halves', function (): void {
    [$near, $far] = tlsSocketPair();
    [$peerNear, $peerFar] = tlsSocketPair();
    $this->tlsCloseables = [$far, $peerFar];

    $id = (int) $near;
    $peerId = (int) $peerNear;
    $conns = [
        $id => ['sock' => $near, 'peer' => $peerId, 'wbuf' => ''],
        $peerId => ['sock' => $peerNear, 'peer' => $id, 'wbuf' => 'lastgasp'],
    ];

    $args = [&$conns, $id];
    tlsPumpMethod('closePair')->invokeArgs($this->tlsCommand, $args);

    // The peer's queued bytes were pushed out before the sockets were torn down.
    expect($conns)->toBe([])
        ->and(fread($peerFar, 4096))->toBe('lastgasp');
});

it('closePair ignores an id it is not tracking', function (): void {
    $conns = [];
    $args = [&$conns, 42];
    tlsPumpMethod('closePair')->invokeArgs($this->tlsCommand, $args);

    expect($conns)->toBe([]);
});

it('runProxyLoop returns success and closes cleanly when it is no longer running', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $this->tlsCloseables = [$server];

    tlsSetRunning($this->tlsCommand, false);
    $result = tlsPumpMethod('runProxyLoop')->invokeArgs($this->tlsCommand, [$server, 8080, null]);

    expect($result)->toBe(ServeOpenBankingTlsCommand::SUCCESS);
});

it('runProxyLoop aborts with failure when the backend process has died', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $this->tlsCloseables = [$server];

    tlsSilenceOutput($this->tlsCommand);
    // An unstarted process reports not-running, so the first tick trips the
    // backend-died guard and exits.
    $backend = new Process(['true']);
    $result = tlsPumpMethod('runProxyLoop')->invokeArgs($this->tlsCommand, [$server, 8080, $backend]);

    expect($result)->toBe(ServeOpenBankingTlsCommand::FAILURE);
});

it('runProxyLoop idles on an empty tick, then stops when the backend dies', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $this->tlsCloseables = [$server];

    tlsSilenceOutput($this->tlsCommand);
    $backend = Mockery::mock(Process::class);
    // Alive for the idle tick, dead on the next one.
    $backend->shouldReceive('isRunning')->andReturn(true, false);
    $result = tlsPumpMethod('runProxyLoop')->invokeArgs($this->tlsCommand, [$server, 8080, $backend]);

    expect($result)->toBe(ServeOpenBankingTlsCommand::FAILURE);
});

it('runProxyLoop pumps a ready tick before the backend stops', function (): void {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $client = @stream_socket_client('tcp://'.stream_socket_get_name($server, false), $errno, $errstr, 1);
    expect($client)->not->toBeFalse();
    $this->tlsCloseables = [$server, $client];

    tlsSilenceOutput($this->tlsCommand);
    $backend = Mockery::mock(Process::class);
    $backend->shouldReceive('isRunning')->andReturn(true, false);

    // A pending client makes the first tick readable, so pumpWritable/pumpReadable
    // both run; backend port 1 is unreachable, so the accepted client is dropped.
    $result = tlsPumpMethod('runProxyLoop')->invokeArgs($this->tlsCommand, [$server, 1, $backend]);

    expect($result)->toBe(ServeOpenBankingTlsCommand::FAILURE);
});
