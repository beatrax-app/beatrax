<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\SyncListenerProcess;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SyncDaemonIdentity;
use Modules\Sync\Public\Services\SyncPorts;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

function credentialledListenerLogger(): object
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

function credentialledListenerUser(): User
{
    $user = User::query()->create([
        'username' => 'sync-credentials-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-this-gate'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $now = CarbonImmutable::now()->toIso8601String();

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => (int) $user->id,
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

    return $user;
}

/**
 * @return array<string, string>
 */
function daemonEnvironmentFor(string $deviceId): array
{
    return [
        SyncDaemonIdentity::ENV_USER => '1',
        SyncDaemonIdentity::ENV_DEVICE => $deviceId,
        SyncDaemonIdentity::ENV_SECRET => str_repeat('a', 64),
        SyncDaemonIdentity::ENV_PUBLIC => str_repeat('b', 64),
    ];
}

// Holds the LAN port for the duration of $work however it has to: this host may
// already be running a real desktop daemon on it, and either way the process
// under test must see portIsBound() answer true.
function withTheLanPortHeld(Closure $work): void
{
    $ours = @stream_socket_server('tcp://127.0.0.1:'.app(SyncPorts::class)->lan(), $errno, $errstr);

    $probe = @fsockopen('127.0.0.1', app(SyncPorts::class)->lan(), $probeErrno, $probeError, 1);

    if ($probe === false) {
        if (is_resource($ours)) {
            fclose($ours);
        }

        test()->markTestSkipped('the LAN sync port could not be held on this host');
    }

    fclose($probe);

    try {
        $work();
    } finally {
        if (is_resource($ours)) {
            fclose($ours);
        }
    }
}

// The daemon is spawned at app boot, while the app is locked, so it cannot hold a
// transport keypair then. The unlock hands one over — and did so by tearing the
// listener down and rebuilding it EVERY time, which is fatal to a ceremony
// spanning the very lock HoldPairingCeremonyOpenOnUnlock exists to carry.
it('leaves a listener alone when it is already running the credentials being offered', function (): void {
    credentialledListenerUser();

    $cache = new CacheRepository(new ArrayStore);
    $cache->forever('sync-listener:credentialled-device', 'device-already-running');

    $logger = credentialledListenerLogger();

    withTheLanPortHeld(function () use ($logger, $cache): void {
        (new SyncListenerProcess(
            app(DeviceRegistryService::class),
            app(SyncPorts::class),
            $logger,
            $cache,
        ))->startIfEnabled(daemonEnvironmentFor('device-already-running'));
    });

    expect($logger->said('already listening with these credentials'))->toBeTrue();
});

// The other half of the same rule: a keyless boot daemon, or one holding some
// other device's identity, MUST be replaced rather than left in place.
it('replaces a listener that is not running the credentials being offered', function (): void {
    credentialledListenerUser();

    $logger = credentialledListenerLogger();

    withTheLanPortHeld(function () use ($logger): void {
        (new SyncListenerProcess(
            app(DeviceRegistryService::class),
            app(SyncPorts::class),
            $logger,
            new CacheRepository(new ArrayStore),
        ))->startIfEnabled(daemonEnvironmentFor('the-freshly-unlocked-device'));
    });

    expect($logger->said('already listening with these credentials'))->toBeFalse();
});
