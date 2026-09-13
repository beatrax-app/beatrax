<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\RelayListenerProcess;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\LocalRelayProvisioner;
use Modules\Sync\Public\Services\SyncPorts;
use Modules\Sync\Public\Testing\DeviceIdentityTestHarness;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Fakes\ChildProcessFake;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

// Every branch here decides whether the reader's relay keeps running. They are
// driven against a port this test owns: pointed at the real one they read the
// developer's own running desktop, and on a machine with none they read the
// opposite branch from the machine next to it.

beforeEach(function (): void {
    $this->relayRestartRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-relay-restart-'.bin2hex(random_bytes(6));
    putenv('NATIVEPHP_STORAGE_PATH='.$this->relayRestartRoot.DIRECTORY_SEPARATOR.'storage');
    $this->relayRestartCloseables = [];
    $this->relayRestartProcesses = [];
});

afterEach(function (): void {
    foreach ($this->relayRestartProcesses as $process) {
        $process->stop(1);
    }
    foreach ($this->relayRestartCloseables as $resource) {
        if (is_resource($resource)) {
            @fclose($resource);
        }
    }
    DeviceIdentityTestHarness::forgetAll();
    putenv('NATIVEPHP_STORAGE_PATH');
});

function relayRestartLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        /**
         * @param  mixed  $level
         * @param  Stringable|string  $message
         * @param  array<mixed>  $context
         */
        public function log($level, $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function said(string $needle): bool
        {
            foreach ($this->messages as $message) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }

            return false;
        }
    };
}

function relayRestartSelfDevice(): void
{
    $user = User::query()->create([
        'username' => 'relay-restart-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-this-gate'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toIso8601String();

    $db->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'desktop-self',
        'name' => 'This desktop',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DeviceIdentityTestHarness::place((int) $user->id);
}

// An operator's own relay: `ensureConfigured()` hands it straight back without
// reading the routing table, so the endpoint is https on every machine.
function relayRestartOperatorEndpoint(): void
{
    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);
    $config->setEndpointUrl('https://relay.example/ws');
}

function relayRestartPort(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($probe)->not->toBeFalse();
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    return (int) explode(':', $name)[1];
}

function relayRestartProcess(object $logger): RelayListenerProcess
{
    return new RelayListenerProcess(
        app(DeviceRegistryService::class),
        app(LocalRelayProvisioner::class),
        app(SyncPorts::class),
        $logger,
    );
}

it('starts the relay when nothing is holding the port', function (): void {
    relayRestartSelfDevice();
    relayRestartOperatorEndpoint();
    $port = relayRestartPort();
    config()->set('sync.relay_port', $port);

    /** @var ChildProcessFake $fake */
    $fake = ChildProcess::fake();

    relayRestartProcess(relayRestartLogger())->startIfEnabled();

    expect($fake->stops)->toBe([]);
    $fake->assertArtisan(
        fn (array|string $cmd, string $alias, ?array $env, ?bool $persistent, ?array $iniSettings): bool => $cmd === 'relay:serve --port='.$port
            && $alias === 'relay-listener'
            && $persistent === true,
    );
});

// The port answers, so the relay is up; the handshake never completes, so it is
// not serving what the QR promises. The old line called that plaintext, which
// is one of the two ways to get here and not the one a broken key takes.
it('restarts a relay that answers the port but completes no handshake', function (): void {
    relayRestartSelfDevice();
    relayRestartOperatorEndpoint();

    $plaintext = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($plaintext)->not->toBeFalse();
    $this->relayRestartCloseables[] = $plaintext;
    $port = (int) explode(':', (string) stream_socket_get_name($plaintext, false))[1];
    config()->set('sync.relay_port', $port);

    /** @var ChildProcessFake $fake */
    $fake = ChildProcess::fake();
    $logger = relayRestartLogger();

    relayRestartProcess($logger)->startIfEnabled();

    expect($fake->stops)->toContain('relay-listener')
        ->and($fake->artisans)->toHaveCount(1)
        ->and($logger->said('completes no TLS handshake'))->toBeTrue();
});

// The branch that decides NOT to kill a healthy relay. The listener runs in its
// own process because the probe's handshake needs something on the other side
// of the accept, and this process is the one doing the probing.
it('leaves a relay that completes a TLS handshake alone', function (): void {
    relayRestartSelfDevice();
    relayRestartOperatorEndpoint();

    $port = relayRestartPort();
    config()->set('sync.relay_port', $port);

    $this->relayRestartProcesses[] = relayRestartTlsListener($this->relayRestartRoot, $port);

    /** @var ChildProcessFake $fake */
    $fake = ChildProcess::fake();

    relayRestartProcess(relayRestartLogger())->startIfEnabled();

    expect($fake->stops)->toBe([])
        ->and($fake->artisans)->toBe([]);
});

// Binds tls:// in another process and drops a marker once it is listening, so
// the probe below never races the bind.
function relayRestartTlsListener(string $root, int $port): Process
{
    if (! is_dir($root)) {
        mkdir($root, 0700, true);
    }
    $certPath = $root.DIRECTORY_SEPARATOR.'probe-cert.pem';
    $keyPath = $root.DIRECTORY_SEPARATOR.'probe-key.pem';
    $marker = $root.DIRECTORY_SEPARATOR.'listening';

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($key)->not->toBeFalse();
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
    expect($csr)->not->toBeFalse();
    $certificate = openssl_csr_sign($csr, null, $key, 1);
    expect($certificate)->not->toBeFalse();
    $certificatePem = '';
    $keyPem = '';
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem);
    file_put_contents($certPath, $certificatePem);
    file_put_contents($keyPath, $keyPem);

    $script = sprintf(
        '$s=@stream_socket_server("tls://127.0.0.1:%d",$e,$m,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,'
        .'stream_context_create(["ssl"=>["local_cert"=>%s,"local_pk"=>%s,"allow_self_signed"=>true]]));'
        .'if($s===false){exit(1);}touch(%s);'
        .'for($i=0;$i<3;$i++){$c=@stream_socket_accept($s,3);if($c!==false){fclose($c);}}',
        $port,
        var_export($certPath, true),
        var_export($keyPath, true),
        var_export($marker, true),
    );

    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->start();

    for ($i = 0; $i < 60; $i++) {
        clearstatcache(true, $marker);
        if (is_file($marker)) {
            return $process;
        }
        usleep(50000);
    }

    expect($marker)->toBeFile();

    return $process;
}
