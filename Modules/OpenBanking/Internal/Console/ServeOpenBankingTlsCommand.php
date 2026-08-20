<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Console\Concerns\RunsTlsProxyLoop;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Symfony\Component\Process\Process;

final class ServeOpenBankingTlsCommand extends Command
{
    use RunsTlsProxyLoop;

    /** @var string */
    protected $signature = 'open-banking:serve-tls
        {--port= : HTTPS loopback port to listen on (defaults to the resolved OAuth redirect port).}
        {--backend-port=8001 : Plain-HTTP port the internal `artisan serve` binds.}
        {--no-backend : Do not launch `artisan serve`; tunnel to an already-running backend on --backend-port.}
        {--regenerate-cert : Force a fresh self-signed certificate before starting.}';

    /** @var string */
    protected $description = 'Terminate TLS on the loopback OAuth redirect port so the Enable Banking consent dance can run locally (HTTPS -> a plain `artisan serve` backend, self-signed 127.0.0.1 certificate).';

    private bool $running = true;

    public function __construct(
        private readonly LoopbackRedirectUri $redirectUri,
        private readonly LoopbackTlsCertificate $certificate,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ports = $this->resolveValidatedPorts();
        if ($ports === null) {
            return self::FAILURE;
        }

        $cert = $this->certificate->ensure($this->option('regenerate-cert') === true);
        $this->announceCertificate($cert);

        $listener = $this->startListener($ports, $cert);
        if ($listener === null) {
            return self::FAILURE;
        }

        $this->installSignalHandlers();
        $this->announceReady($ports['front'], $ports['backend'], $listener['noBackend']);

        $exitCode = $this->runProxyLoop($listener['server'], $ports['backend'], $listener['backend']);

        $this->cleanup($listener['server'], $listener['backend']);

        return $exitCode;
    }

    /**
     * @return array{front: int, backend: int}|null null when a port is invalid,
     *                                              the reason already reported to the operator
     */
    private function resolveValidatedPorts(): ?array
    {
        $frontPort = $this->resolveFrontPort();
        $backendPort = (int) $this->option('backend-port');

        if ($frontPort <= 0 || $backendPort <= 0) {
            $this->error('Both the HTTPS port and the backend port must be positive integers.');

            return null;
        }
        if ($frontPort === $backendPort) {
            $this->error("The HTTPS port ({$frontPort}) and the backend port ({$backendPort}) must differ.");

            return null;
        }

        return ['front' => $frontPort, 'backend' => $backendPort];
    }

    /**
     * @param  array{front: int, backend: int}  $ports
     * @param  array{cert: string, key: string}  $cert
     * @return array{server: resource, backend: Process|null, noBackend: bool}|null
     *                                                                              null when the backend or listener could not start, already reported
     */
    private function startListener(array $ports, array $cert): ?array
    {
        $backend = $this->acquireBackend($ports['backend'], $ports['front']);
        if ($backend === false) {
            return null;
        }

        $server = $this->openTlsServer($ports['front'], $cert);
        if ($server === null) {
            $this->stopBackend($backend);

            return null;
        }

        return [
            'server' => $server,
            'backend' => $backend,
            'noBackend' => $this->option('no-backend') === true,
        ];
    }

    /**
     * @return Process|false|null Process = a managed backend to stop on exit;
     *                            null = an external backend already reachable; false = a startup failure
     *                            already reported to the operator
     */
    private function acquireBackend(int $backendPort, int $frontPort): Process|false|null
    {
        if ($this->option('no-backend') === true) {
            if ($this->backendReachable($backendPort)) {
                return null;
            }
            $this->error("No backend is reachable on 127.0.0.1:{$backendPort} (and --no-backend was given).");

            return false;
        }

        return $this->startBackend($backendPort, $frontPort) ?? false;
    }

    /**
     * @param  array{cert: string, key: string}  $cert
     */
    private function announceCertificate(array $cert): void
    {
        $this->line('<info>Loopback TLS certificate:</info> '.$cert['cert']);
        $this->line('  fingerprint (SHA-256): '.$this->fingerprint($cert['cert']));
    }

    private function announceReady(int $frontPort, int $backendPort, bool $noBackend): void
    {
        $this->newLine();
        $this->info("HTTPS loopback listener ready → https://127.0.0.1:{$frontPort}");
        $this->line('  OAuth redirect URI: <comment>'.$this->redirectUri->forProvider('open-banking', scheme: 'https').'</comment>');
        $this->line('  Tunnelling to plain HTTP backend on 127.0.0.1:'.$backendPort.($noBackend ? ' (external)' : ''));
        $this->line('  Your browser will warn about the self-signed certificate — accept it (or trust the cert above) once.');
        $this->comment('  Press Ctrl+C to stop.');
        $this->newLine();
    }

    private function resolveFrontPort(): int
    {
        $option = $this->option('port');
        if (is_string($option) && ctype_digit($option)) {
            return (int) $option;
        }

        // Derived from the redirect URI so the listener can never drift from
        // the port Enable Banking has registered.
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

        // Deliberately left blocking: stream_socket_accept() completes the
        // multi-round-trip TLS handshake and needs a blocking socket to do it.
        // accept() only runs after stream_select() reports a pending
        // connection, so it never stalls the loop.

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
}
