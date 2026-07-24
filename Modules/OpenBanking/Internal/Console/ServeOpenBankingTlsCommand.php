<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Symfony\Component\Process\Process;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class ServeOpenBankingTlsCommand extends Command
{
    /** @var string */
    protected $signature = 'open-banking:serve-tls
        {--port= : HTTPS loopback port to listen on (defaults to the resolved OAuth redirect port).}
        {--backend-port=8001 : Plain-HTTP port the internal `artisan serve` binds.}
        {--no-backend : Do not launch `artisan serve`; tunnel to an already-running backend on --backend-port.}
        {--regenerate-cert : Force a fresh self-signed certificate before starting.}';

    /** @var string */
    protected $description = 'Terminate TLS on the loopback OAuth redirect port so the Enable Banking consent dance can run locally (HTTPS -> a plain `artisan serve` backend, self-signed 127.0.0.1 certificate).';

    private const READ_CHUNK = 65536;

    private bool $running = true;

    public function __construct(
        private readonly LoopbackRedirectUri $redirectUri,
        private readonly LoopbackTlsCertificate $certificate,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $frontPort = $this->resolveFrontPort();
        $backendPort = (int) $this->option('backend-port');

        if ($frontPort <= 0 || $backendPort <= 0) {
            $this->error('Both the HTTPS port and the backend port must be positive integers.');

            return self::FAILURE;
        }
        if ($frontPort === $backendPort) {
            $this->error("The HTTPS port ({$frontPort}) and the backend port ({$backendPort}) must differ.");

            return self::FAILURE;
        }

        $cert = $this->certificate->ensure($this->option('regenerate-cert') === true);
        $this->line('<info>Loopback TLS certificate:</info> '.$cert['cert']);
        $this->line('  fingerprint (SHA-256): '.$this->fingerprint($cert['cert']));

        $noBackend = $this->option('no-backend') === true;
        $backend = null;
        if (! $noBackend) {
            $backend = $this->startBackend($backendPort, $frontPort);
            if ($backend === null) {
                return self::FAILURE;
            }
        } elseif (! $this->backendReachable($backendPort)) {
            $this->error("No backend is reachable on 127.0.0.1:{$backendPort} (and --no-backend was given).");

            return self::FAILURE;
        }

        $server = $this->openTlsServer($frontPort, $cert);
        if ($server === null) {
            $this->stopBackend($backend);

            return self::FAILURE;
        }

        $this->installSignalHandlers();

        $this->newLine();
        $this->info("HTTPS loopback listener ready → https://127.0.0.1:{$frontPort}");
        $this->line('  OAuth redirect URI: <comment>'.$this->redirectUri->forProvider('open-banking', scheme: 'https').'</comment>');
        $this->line('  Tunnelling to plain HTTP backend on 127.0.0.1:'.$backendPort.($noBackend ? ' (external)' : ''));
        $this->line('  Your browser will warn about the self-signed certificate — accept it (or trust the cert above) once.');
        $this->comment('  Press Ctrl+C to stop.');
        $this->newLine();

        $exitCode = $this->runProxyLoop($server, $backendPort, $backend);

        $this->cleanup($server, $backend);

        return $exitCode;
    }

    private function resolveFrontPort(): int
    {
        $option = $this->option('port');
        if (is_string($option) && ctype_digit($option)) {
            return (int) $option;
        }

        // Single source of truth: reuse the exact port the connector prints
        // and computes for the redirect URI, so the listener can never drift
        // from what Enable Banking has registered.
        $uri = $this->redirectUri->forProvider('open-banking', scheme: 'https');
        $port = parse_url($uri, PHP_URL_PORT);

        return is_int($port) && $port > 0 ? $port : 8000;
    }

    /**
     * @param  array{cert: string, key: string}  $cert
     * @return resource|null
     */
    private function openTlsServer(int $port, array $cert)
    {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $cert['cert'],
                'local_pk' => $cert['key'],
                'allow_self_signed' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            'tls://127.0.0.1:'.$port,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($server === false) {
            $this->error("Could not bind the HTTPS listener on 127.0.0.1:{$port}: {$errstr} (errno {$errno}).");
            $this->line('  Is another process already using that port?');

            return null;
        }

        // Deliberately left blocking: the TLS handshake is completed inside
        // stream_socket_accept(), which needs a blocking socket to finish its
        // multi-round-trip exchange. We only ever call accept() after
        // stream_select() reports a pending connection, so it never stalls.

        return $server;
    }

    private function startBackend(int $backendPort, int $frontPort): ?Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$backendPort],
            UserDataPathService::projectPath(),
            // Real env vars win over .env (Laravel's immutable loader), so the
            // backend generates same-origin HTTPS URLs for the tunnelled front.
            ['APP_URL' => 'https://127.0.0.1:'.$frontPort],
        );
        $process->setTimeout(null);
        $process->start();

        for ($i = 0; $i < 100; $i++) {
            if (! $process->isRunning()) {
                $this->error('The backend `artisan serve` exited during startup:');
                $this->line(trim($process->getErrorOutput().$process->getOutput()));

                return null;
            }
            if ($this->backendReachable($backendPort)) {
                $this->line("<info>Backend:</info> php artisan serve on 127.0.0.1:{$backendPort}");

                return $process;
            }
            usleep(100000);
        }

        $this->error("The backend did not become reachable on 127.0.0.1:{$backendPort} within 10s.");
        $this->stopBackend($process);

        return null;
    }

    private function backendReachable(int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * @param  resource  $server
     */
    private function runProxyLoop($server, int $backendPort, ?Process $backend): int
    {
        // Keyed by (int) resource id. Each entry points at its peer's id and
        // carries the bytes waiting to be written *to this socket*.
        /** @var array<int, array{sock: resource, peer: int, wbuf: string}> $conns */
        $conns = [];

        while ($this->running) {
            if ($backend !== null && ! $backend->isRunning()) {
                $this->newLine();
                $this->error('Backend `artisan serve` stopped unexpectedly — shutting down the tunnel.');

                return self::FAILURE;
            }

            $read = [$server];
            $write = [];
            foreach ($conns as $conn) {
                $read[] = $conn['sock'];
                if ($conn['wbuf'] !== '') {
                    $write[] = $conn['sock'];
                }
            }

            $except = null;
            $ready = @stream_select($read, $write, $except, 1);
            if ($ready === false) {
                continue;
            }
            if ($ready === 0) {
                continue;
            }

            foreach ($write as $sock) {
                $id = $this->sockId($sock);
                if (! isset($conns[$id])) {
                    continue;
                }
                $this->flush($conns, $id);
            }

            foreach ($read as $sock) {
                if ($sock === $server) {
                    $this->accept($server, $backendPort, $conns);

                    continue;
                }

                $id = $this->sockId($sock);
                if (! isset($conns[$id])) {
                    continue;
                }

                $data = @fread($sock, self::READ_CHUNK);
                if ($data === false || ($data === '' && feof($sock))) {
                    $this->closePair($conns, $id);

                    continue;
                }
                if ($data === '') {
                    continue;
                }

                $peerId = $conns[$id]['peer'];
                if (isset($conns[$peerId])) {
                    $conns[$peerId]['wbuf'] .= $data;
                    $this->flush($conns, $peerId);
                }
            }
        }

        foreach (array_keys($conns) as $id) {
            $this->closePair($conns, $id);
        }

        return self::SUCCESS;
    }

    /**
     * @param  resource  $server
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     */
    private function accept($server, int $backendPort, array &$conns): void
    {
        // A short handshake timeout keeps a stalled client from blocking the
        // single-threaded loop; local UAT traffic completes far inside it.
        $client = @stream_socket_accept($server, 5);
        if ($client === false) {
            return;
        }
        stream_set_blocking($client, false);

        $errno = 0;
        $errstr = '';
        $upstream = @stream_socket_client(
            'tcp://127.0.0.1:'.$backendPort,
            $errno,
            $errstr,
            5,
        );
        if ($upstream === false) {
            fclose($client);

            return;
        }
        stream_set_blocking($upstream, false);

        $clientId = $this->sockId($client);
        $upstreamId = $this->sockId($upstream);

        $conns[$clientId] = ['sock' => $client, 'peer' => $upstreamId, 'wbuf' => ''];
        $conns[$upstreamId] = ['sock' => $upstream, 'peer' => $clientId, 'wbuf' => ''];
    }

    /**
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     */
    private function flush(array &$conns, int $id): void
    {
        if (! isset($conns[$id]) || $conns[$id]['wbuf'] === '') {
            return;
        }

        $written = @fwrite($conns[$id]['sock'], $conns[$id]['wbuf']);
        if ($written === false) {
            $this->closePair($conns, $id);

            return;
        }

        $conns[$id]['wbuf'] = substr($conns[$id]['wbuf'], $written);
    }

    /**
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     */
    private function closePair(array &$conns, int $id): void
    {
        if (! isset($conns[$id])) {
            return;
        }

        $peerId = $conns[$id]['peer'];

        if (isset($conns[$peerId]) && $conns[$peerId]['wbuf'] !== '') {
            @fwrite($conns[$peerId]['sock'], $conns[$peerId]['wbuf']);
        }

        foreach ([$id, $peerId] as $closeId) {
            if (isset($conns[$closeId])) {
                @fclose($conns[$closeId]['sock']);
                unset($conns[$closeId]);
            }
        }
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->running = false;
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    /**
     * @param  resource  $server
     */
    private function cleanup($server, ?Process $backend): void
    {
        @fclose($server);
        $this->stopBackend($backend);
        $this->newLine();
        $this->info('HTTPS loopback listener stopped.');
    }

    private function stopBackend(?Process $backend): void
    {
        if ($backend !== null && $backend->isRunning()) {
            $backend->stop(5);
        }
    }

    private function fingerprint(string $certPath): string
    {
        $pem = @file_get_contents($certPath);
        if ($pem === false) {
            return 'unavailable';
        }

        $digest = openssl_x509_fingerprint($pem, 'sha256');
        if ($digest === false) {
            return 'unavailable';
        }

        return strtoupper(implode(':', str_split($digest, 2)));
    }

    /**
     * @param  resource  $sock
     */
    private function sockId($sock): int
    {
        return (int) $sock;
    }
}
