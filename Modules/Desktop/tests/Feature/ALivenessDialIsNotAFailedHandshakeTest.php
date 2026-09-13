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
use Modules\Sync\Tests\Support\AmphpTlsProbeListener;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Fakes\ChildProcessFake;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

// The listener under test is the client. What writes the line is the relay's
// own accept path, so the server here is the real one, stood up by the module
// that owns amphp -- the same seam that wrote 5318 warnings to the shipped
// desktop's log.

beforeEach(function (): void {
    $this->livenessDialRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-liveness-dial-'.bin2hex(random_bytes(6));
    putenv('NATIVEPHP_STORAGE_PATH='.$this->livenessDialRoot.DIRECTORY_SEPARATOR.'storage');
    $this->livenessDialListener = AmphpTlsProbeListener::start(
        $this->livenessDialRoot,
        base_path('vendor/autoload.php'),
    );
});

afterEach(function (): void {
    $this->livenessDialListener->stop();
    DeviceIdentityTestHarness::forgetAll();
    putenv('NATIVEPHP_STORAGE_PATH');
});

function livenessDialCollectingLogger(): object
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
    };
}

function livenessDialSelfDevice(): void
{
    $user = User::query()->create([
        'username' => 'liveness-dial-'.bin2hex(random_bytes(4)),
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

// An operator's own relay: ensureConfigured() hands it straight back without
// reading the routing table, so the endpoint is https on every machine.
function livenessDialOperatorEndpoint(): void
{
    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);
    $config->setEndpointUrl('https://relay.example/ws');
}

function livenessDialProcess(object $logger): RelayListenerProcess
{
    return new RelayListenerProcess(
        app(DeviceRegistryService::class),
        app(LocalRelayProvisioner::class),
        app(SyncPorts::class),
        $logger,
    );
}

// The defect. A dial that opens TCP and closes without a ClientHello is a client
// disconnect, and the relay's listener has no way to call it anything but a
// failed negotiation -- so the supervisor must not make that dial.
it('leaves no negotiation warning behind when the supervisor checks the relay is alive', function (): void {
    livenessDialSelfDevice();
    livenessDialOperatorEndpoint();
    config()->set('sync.relay_port', $this->livenessDialListener->port);

    /** @var ChildProcessFake $fake */
    $fake = ChildProcess::fake();

    livenessDialProcess(livenessDialCollectingLogger())->startIfEnabled();

    // The positive control: silence is only evidence once the server is known to
    // have seen this dial and written about it.
    expect($this->livenessDialListener->waitFor('TLS negotiated with'))->toBeTrue();

    expect($this->livenessDialListener->log())->not->toContain('TLS negotiation failed')
        ->and($fake->stops)->toBe([])
        ->and($fake->artisans)->toBe([]);
});

// The shape that was removed, against the same server, so the absence above is
// the dial's doing and not a logger that writes nothing.
it('still reports a negotiation failure for a client that closes before the ClientHello', function (): void {
    $socket = @fsockopen('127.0.0.1', $this->livenessDialListener->port, $errno, $errstr, 1);
    expect($socket)->not->toBeFalse();
    if ($socket !== false) {
        fclose($socket);
    }

    expect($this->livenessDialListener->waitFor('TLS negotiation failed'))->toBeTrue()
        ->and($this->livenessDialListener->log())->toContain('WARNING');
});

// The signal this change exists to protect: a peer that fails a handshake is
// still a warning, at the same level, in the same words.
it('still reports a negotiation failure for a client that sends a malformed ClientHello', function (): void {
    $socket = @fsockopen('127.0.0.1', $this->livenessDialListener->port, $errno, $errstr, 1);
    expect($socket)->not->toBeFalse();

    if ($socket !== false) {
        // A TLS record header announcing a handshake, followed by bytes that are
        // not one. The peer speaks first and speaks nonsense, which is the shape
        // a hostile or misconfigured dialler has.
        fwrite($socket, "\x16\x03\x01\x00\x2c\x01\x00\x00\x28".str_repeat("\xff", 40));
        fflush($socket);
        usleep(200000);
        fclose($socket);
    }

    expect($this->livenessDialListener->waitFor('TLS negotiation failed'))->toBeTrue()
        ->and($this->livenessDialListener->log())->toContain('WARNING');
});
