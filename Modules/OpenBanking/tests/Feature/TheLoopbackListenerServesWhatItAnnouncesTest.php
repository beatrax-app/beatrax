<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Console\ServeOpenBankingTlsCommand;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

// The listener itself, on real sockets. `stream_socket_server('tls://...')`
// reads neither half of the material and binds whatever it is given, so
// everything these cover is invisible until a browser connects.

beforeEach(function (): void {
    $this->listenerDir = sys_get_temp_dir().'/ob-listener-'.bin2hex(random_bytes(6));
    $this->listenerCommand = new ServeOpenBankingTlsCommand(
        app(LoopbackRedirectUri::class),
        new LoopbackTlsCertificate($this->listenerDir),
    );
    $this->listenerCommand->setOutput(new OutputStyle(new ArrayInput([]), $this->listenerOutput = new BufferedOutput));
    $this->listenerCloseables = [];
    $this->listenerProcesses = [];
});

afterEach(function (): void {
    foreach ($this->listenerProcesses as $process) {
        $process->stop(1);
    }
    foreach ($this->listenerCloseables as $resource) {
        if (is_resource($resource)) {
            @fclose($resource);
        }
    }
    if (is_dir($this->listenerDir)) {
        foreach (glob($this->listenerDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->listenerDir);
    }
});

function listenerFreePort(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($probe)->not->toBeFalse();
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    return (int) explode(':', $name)[1];
}

/**
 * @param  array{cert: string, key: string}  $cert
 * @return resource|null
 */
function listenerOpen(ServeOpenBankingTlsCommand $command, int $port, array $cert)
{
    $method = new ReflectionMethod(ServeOpenBankingTlsCommand::class, 'openTlsServer');
    $method->setAccessible(true);

    /** @var resource|null $server */
    $server = $method->invoke($command, $port, $cert);

    return $server;
}

// A client in its own process, because the accept() that finishes the
// handshake blocks this one until the ClientHello it is waiting for arrives.
function listenerDialInAnotherProcess(int $port): Process
{
    $script = sprintf(
        '$c=@stream_socket_client("tls://127.0.0.1:%d",$e,$s,5,STREAM_CLIENT_CONNECT,'
        .'stream_context_create(["ssl"=>["verify_peer"=>false,"verify_peer_name"=>false]]));'
        .'if($c!==false){fwrite($c,"GET / HTTP/1.0\r\n\r\n");usleep(300000);}',
        $port,
    );

    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->start();

    return $process;
}

it('binds the loopback interface and exactly the port it was handed', function (): void {
    $cert = (new LoopbackTlsCertificate($this->listenerDir))->ensure();
    $port = listenerFreePort();

    $server = listenerOpen($this->listenerCommand, $port, $cert);

    expect($server)->not->toBeNull();
    $this->listenerCloseables[] = $server;

    expect(stream_socket_get_name($server, false))->toBe('127.0.0.1:'.$port);
});

// `tls://` on its own offers every version the linked OpenSSL permits, which
// makes the floor a property of the build rather than of this application.
it('states its own TLS floor instead of inheriting the openssl build', function (): void {
    $cert = (new LoopbackTlsCertificate($this->listenerDir))->ensure();
    $port = listenerFreePort();

    $server = listenerOpen($this->listenerCommand, $port, $cert);
    expect($server)->not->toBeNull();
    $this->listenerCloseables[] = $server;

    $options = stream_context_get_options($server);
    $ssl = $options['ssl'] ?? null;

    expect($ssl)->toBeArray()
        ->and(is_array($ssl) ? ($ssl['crypto_method'] ?? null) : null)
        ->toBe(STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
});

// The whole surface end to end: the key on disk does not open the certificate,
// which the bind would not have noticed. Handed that pair, the listener came
// up, the command announced it ready, and every connection was reset.
it('completes a real handshake on material whose key on disk did not match', function (): void {
    $first = (new LoopbackTlsCertificate($this->listenerDir))->ensure();

    $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($foreign)->not->toBeFalse();
    $foreignPem = '';
    openssl_pkey_export($foreign, $foreignPem);
    file_put_contents($first['key'], $foreignPem);

    $cert = (new LoopbackTlsCertificate($this->listenerDir))->ensure();
    $port = listenerFreePort();

    $server = listenerOpen($this->listenerCommand, $port, $cert);
    expect($server)->not->toBeNull();
    $this->listenerCloseables[] = $server;

    $this->listenerProcesses[] = listenerDialInAnotherProcess($port);

    $connection = @stream_socket_accept($server, 5);
    expect($connection)->not->toBeFalse();
    $this->listenerCloseables[] = $connection;

    $crypto = stream_get_meta_data($connection)['crypto'] ?? null;

    expect($crypto)->toBeArray()
        ->and(is_array($crypto) ? ($crypto['protocol'] ?? null) : null)
        ->toBeIn(['TLSv1.2', 'TLSv1.3']);
});

// The hint below the error asks about a port conflict, so the line above it has
// to carry what the operating system actually said.
it('names the reason it could not bind when another process holds the port', function (): void {
    $cert = (new LoopbackTlsCertificate($this->listenerDir))->ensure();

    $holder = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($holder)->not->toBeFalse();
    $this->listenerCloseables[] = $holder;
    $port = (int) explode(':', (string) stream_socket_get_name($holder, false))[1];

    $server = listenerOpen($this->listenerCommand, $port, $cert);

    expect($server)->toBeNull()
        ->and($this->listenerOutput->fetch())
        ->toContain('Could not bind the HTTPS listener on 127.0.0.1:'.$port)
        ->toContain('Address already in use');
});
