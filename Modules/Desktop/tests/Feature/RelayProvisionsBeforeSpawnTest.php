<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\RelayListenerProcess;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\LocalRelayProvisioner;
use Modules\Sync\Public\Services\SyncPorts;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-relay-boot-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

function relayBootSpyLogger(): object
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

function relayBootSelfDevice(): void
{
    $user = User::query()->create([
        'username' => 'relay-boot-'.bin2hex(random_bytes(4)),
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
}

// `relay:serve` picks a TLS or a plaintext bind exactly once at startup, by
// asking whether the certificate exists. Starting it before writing the
// material gave a relay that spoke plaintext for life while publishing an
// https endpoint into the pairing QR, and a ChildProcess outlives a relaunch.
it('has the certificate and the endpoint in place by the time it starts the relay', function (): void {
    relayBootSelfDevice();

    /** @var RelayTlsMaterial $tls */
    $tls = app(RelayTlsMaterial::class);
    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);

    expect($tls->exists())->toBeFalse();

    (new RelayListenerProcess(
        app(DeviceRegistryService::class),
        app(LocalRelayProvisioner::class),
        app(SyncPorts::class),
        relayBootSpyLogger(),
    ))->startIfEnabled();

    if ($config->endpointUrl() === null) {
        $this->markTestSkipped('no routable LAN address in this environment');
    }

    // Whether the child process spawns is NativePHP's business and unavailable
    // outside the desktop runtime; what this pins is the state it would read.
    expect($tls->exists())->toBeTrue()
        ->and($config->endpointUrl())->toStartWith('https://')
        ->and($config->authToken())->not->toBeNull()
        ->and($config->pin())->toStartWith('sha256//');
});

it('keeps the private key unreadable to anyone else', function (): void {
    /** @var RelayTlsMaterial $tls */
    $tls = app(RelayTlsMaterial::class);

    $tls->ensure('192.0.2.10');

    $mode = fileperms($tls->keyPath());

    expect($mode)->not->toBeFalse()
        ->and($mode & 0o777)->toBe(0o600);
});
