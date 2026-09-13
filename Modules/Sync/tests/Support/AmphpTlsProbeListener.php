<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

// The relay's own accept path, in a process of its own: amphp's real
// SocketClientFactory, handed a logger that writes to a file. A test that
// wants to know what a dial looks like from the listening end reads that file
// rather than reimplementing the listener's judgement.
final class AmphpTlsProbeListener
{
    private const int TICK_MICROSECONDS = 50_000;

    private const int TICKS = 100;

    private function __construct(
        public readonly int $port,
        public readonly string $logPath,
        private readonly Process $process,
    ) {}

    // Assertions are forced on because amphp reports its successes through
    // assert(); without them a caller asking "and it said nothing" cannot tell
    // a silent dial from a listener that writes nothing at all.
    public static function start(string $root, string $autoloadPath): self
    {
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('could not create '.$root);
        }

        [$certPath, $keyPath] = self::certificate($root);
        $port = self::freePort();
        $logPath = $root.DIRECTORY_SEPARATOR.'amphp-listener.log';
        $marker = $root.DIRECTORY_SEPARATOR.'amphp-listening';
        $scriptPath = $root.DIRECTORY_SEPARATOR.'amphp-listener.php';

        file_put_contents($scriptPath, self::script($autoloadPath));

        $process = new Process([
            PHP_BINARY,
            '-d', 'zend.assertions=1',
            $scriptPath,
            (string) $port,
            $certPath,
            $keyPath,
            $logPath,
            $marker,
        ]);
        $process->start();

        for ($tick = 0; $tick < self::TICKS; $tick++) {
            clearstatcache(true, $marker);
            if (is_file($marker)) {
                return new self($port, $logPath, $process);
            }
            usleep(self::TICK_MICROSECONDS);
        }

        $process->stop(1);

        throw new RuntimeException('the amphp listener never bound '.$port.': '.$process->getErrorOutput());
    }

    public function stop(): void
    {
        $this->process->stop(1);
    }

    public function log(): string
    {
        clearstatcache(true, $this->logPath);

        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function waitFor(string $needle): bool
    {
        for ($tick = 0; $tick < self::TICKS; $tick++) {
            if (str_contains($this->log(), $needle)) {
                return true;
            }
            usleep(self::TICK_MICROSECONDS);
        }

        return false;
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($probe === false) {
            throw new RuntimeException('could not hold a loopback port: '.$errstr);
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) explode(':', $name)[1];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function certificate(string $root): array
    {
        $certPath = $root.DIRECTORY_SEPARATOR.'amphp-cert.pem';
        $keyPath = $root.DIRECTORY_SEPARATOR.'amphp-key.pem';

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => '127.0.0.1'], $key);
        $certificate = $csr === false || $key === false ? false : openssl_csr_sign($csr, null, $key, 1);

        if ($key === false || $certificate === false) {
            throw new RuntimeException('could not mint a loopback certificate');
        }

        $certificatePem = '';
        $keyPem = '';
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($certPath, $certificatePem);
        file_put_contents($keyPath, $keyPem);

        return [$certPath, $keyPath];
    }

    private static function script(string $autoloadPath): string
    {
        $script = '<?php'."\n".<<<'PHP'

declare(strict_types=1);

require AUTOLOAD;

use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;

[, $port, $certFile, $keyFile, $logFile, $marker] = $argv;

$logger = new class($logFile) extends AbstractLogger
{
    public function __construct(private string $file) {}

    public function log($level, $message, array $context = []): void
    {
        file_put_contents($this->file, strtoupper((string) $level).' '.$message."\n", FILE_APPEND);
    }
};

$tls = (new ServerTlsContext)->withDefaultCertificate(new Certificate($certFile, $keyFile));
$server = Amp\Socket\listen('127.0.0.1:'.$port, (new BindContext)->withTlsContext($tls));
$factory = new SocketClientFactory($logger);

EventLoop::queue(static function () use ($server, $factory): void {
    while (($socket = $server->accept()) !== null) {
        EventLoop::queue(static function () use ($socket, $factory): void {
            try {
                $factory->createClient($socket);
            } catch (Throwable) {
            }

            $socket->close();
        });
    }
});

EventLoop::delay(30, static fn () => exit(0));

touch($marker);

EventLoop::run();
PHP;

        return str_replace('AUTOLOAD', var_export($autoloadPath, true), $script);
    }
}
