<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Console\ServeOpenBankingTlsCommand;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Ctrl+C is the documented way to stop this, and the loop only ever reads the
// flag the handler sets. A listener that holds the redirect port after the
// operator has stopped it is the failure the next `serve-tls` reports as a
// port conflict.

beforeEach(function (): void {
    $this->stopDir = sys_get_temp_dir().'/ob-stop-'.bin2hex(random_bytes(6));
    $this->stopCommand = new ServeOpenBankingTlsCommand(
        app(LoopbackRedirectUri::class),
        new LoopbackTlsCertificate($this->stopDir),
    );
    $this->stopCommand->setOutput(new OutputStyle(new ArrayInput([]), $this->stopOutput = new BufferedOutput));
});

afterEach(function (): void {
    if (is_dir($this->stopDir)) {
        foreach (glob($this->stopDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stopDir);
    }
});

function stopMethod(string $name): ReflectionMethod
{
    $method = new ReflectionMethod(ServeOpenBankingTlsCommand::class, $name);
    $method->setAccessible(true);

    return $method;
}

function stopRunningFlag(ServeOpenBankingTlsCommand $command): bool
{
    $property = new ReflectionProperty(ServeOpenBankingTlsCommand::class, 'running');
    $property->setAccessible(true);

    return (bool) $property->getValue($command);
}

it('lowers the running flag on the signal the operator is told to send', function (): void {
    $previousAsync = pcntl_async_signals();

    stopMethod('installSignalHandlers')->invoke($this->stopCommand);

    $handler = pcntl_signal_get_handler(SIGINT);
    expect($handler)->toBeInstanceOf(Closure::class)
        ->and(stopRunningFlag($this->stopCommand))->toBeTrue();

    $handler(SIGINT);

    expect(stopRunningFlag($this->stopCommand))->toBeFalse()
        ->and(pcntl_signal_get_handler(SIGTERM))->toBeInstanceOf(Closure::class);

    pcntl_signal(SIGINT, SIG_DFL);
    pcntl_signal(SIGTERM, SIG_DFL);
    pcntl_async_signals($previousAsync);
});

it('closes the listening socket so the port is free for the next run', function (): void {
    $cert = (new LoopbackTlsCertificate($this->stopDir))->ensure();

    $method = new ReflectionMethod(ServeOpenBankingTlsCommand::class, 'openTlsServer');
    $method->setAccessible(true);

    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($probe)->not->toBeFalse();
    $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
    fclose($probe);

    /** @var resource|null $server */
    $server = $method->invoke($this->stopCommand, $port, $cert);
    expect($server)->not->toBeNull();

    stopMethod('cleanup')->invoke($this->stopCommand, $server, null);

    expect(is_resource($server))->toBeFalse()
        ->and($this->stopOutput->fetch())->toContain('HTTPS loopback listener stopped.');

    // Free, not merely closed: the next run has to be able to take it back.
    $rebound = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
    expect($rebound)->not->toBeFalse();
    fclose($rebound);
});
