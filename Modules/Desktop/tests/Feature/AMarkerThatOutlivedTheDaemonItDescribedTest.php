<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\SyncListenerProcess;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SyncDaemonIdentity;
use Modules\Sync\Public\Services\SyncPorts;
use Modules\Sync\Public\Testing\DeviceIdentityTestHarness;
use Native\Desktop\Contracts\ChildProcess as ChildProcessContract;
use Native\Desktop\Facades\ChildProcess;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

afterEach(function (): void {
    DeviceIdentityTestHarness::forgetAll();
});

// Measured on the desktop install on 2026-09-11. The daemon holding :51337 had
// started at 22:14:55 with no BEATRAX_SYNC_* variable in its environment; the
// unlock at 22:19:18 logged "already listening with these credentials" and left
// it there. What answered that unlock was
// storage/framework/cache/data/f5/d1/f5d1584a1161e4010c55481e64f0d9e0aec01009,
// written at 20:16 by the run of the app before this one and holding the Mac's
// own device id, 7dd3b78d-5456-42dd-a8ab-b7daad900183.

function outlivedMarkerLogger(): object
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

function outlivedMarkerUser(): User
{
    $user = User::query()->create([
        'username' => 'listener-marker-'.bin2hex(random_bytes(4)),
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

    DeviceIdentityTestHarness::place((int) $user->id);

    return $user;
}

/** @return array<string, string> */
function outlivedMarkerEnvironmentFor(string $deviceId): array
{
    return [
        SyncDaemonIdentity::ENV_USER => '1',
        SyncDaemonIdentity::ENV_DEVICE => $deviceId,
        SyncDaemonIdentity::ENV_SECRET => str_repeat('a', 64),
        SyncDaemonIdentity::ENV_PUBLIC => str_repeat('b', 64),
    ];
}

// A store on the far side of the process, not an ArrayStore: the whole defect
// is that the value outlives the run of the app that wrote it, and a store
// living in the object graph cannot express that. Each call builds its own
// repository, so nothing in memory carries a value from one caller to the next.
function outlivedMarkerStore(): CacheRepository
{
    /** @var array<string, mixed> $config */
    $config = config('cache.stores.database');

    /** @var CacheManager $manager */
    $manager = app('cache');

    return $manager->build($config);
}

// A healthy shell: it runs what it is asked to run and reports that environment
// back, so a restart this test does not see is a restart that did not happen.
function aShellThatRunsWhatItIsAsked(): object
{
    $double = new class implements ChildProcessContract
    {
        /** @var array<string, string>|null */
        public ?array $env = null;

        /** @var list<array<string, string>|null> */
        public array $started = [];

        /** @var list<string|null> */
        public array $stopped = [];

        public function get(?string $alias = null): static
        {
            return $this;
        }

        /** @return array<int, static> */
        public function all(): array
        {
            return [$this];
        }

        /** @param  array<string, string>|null  $env */
        public function start(string|array $cmd, string $alias, ?string $cwd = null, ?array $env = null, bool $persistent = false): static
        {
            return $this;
        }

        /**
         * @param  array<string, string>|null  $env
         * @param  array<string, string>|null  $iniSettings
         */
        public function php(string|array $cmd, string $alias, ?array $env = null, ?bool $persistent = false, ?array $iniSettings = null): static
        {
            return $this;
        }

        /**
         * @param  array<string, string>|null  $env
         * @param  array<string, string>|null  $iniSettings
         */
        public function artisan(string|array $cmd, string $alias, ?array $env = null, ?bool $persistent = false, ?array $iniSettings = null): static
        {
            $this->started[] = $env;
            $this->env = $env;

            return $this;
        }

        /** @param  array<string, string>|null  $env */
        public function node(string|array $cmd, string $alias, ?array $env = null, ?bool $persistent = false): static
        {
            return $this;
        }

        public function stop(?string $alias = null): void
        {
            $this->stopped[] = $alias;
        }

        public function restart(?string $alias = null): static
        {
            return $this;
        }

        public function message(string $message, ?string $alias = null): static
        {
            return $this;
        }
    };

    ChildProcess::swap($double);

    return $double;
}

function outlivedMarkerListener(CacheRepository $cache, object $logger): SyncListenerProcess
{
    return new SyncListenerProcess(
        app(DeviceRegistryService::class),
        app(SyncPorts::class),
        $logger,
        $cache,
    );
}

// The boot spawn has to see a free port and the unlock a bound one, which no
// fixed port can give a host that may already be running a real desktop daemon.
// Asking the kernel for one and releasing it leaves a port this test owns, so
// nothing here is skipped for a port somebody else is holding.
function aSyncPortThisTestOwns(): int
{
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($probe === false) {
        throw new RuntimeException("no loopback port could be reserved: {$error}");
    }

    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    $port = (int) substr($name, (int) strrpos($name, ':') + 1);

    config(['sync.port' => $port]);

    return $port;
}

/** @return resource */
function aDaemonHoldingTheSyncPort(int $port)
{
    $held = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $error);

    if ($held === false) {
        throw new RuntimeException("the reserved port {$port} could not be held: {$error}");
    }

    return $held;
}

// THE DEFECT. A second start of the app finds the key its first start left
// behind, spawns a keyless daemon over the top of it, and then reads that key
// as a description of the daemon it just spawned.
it('replaces a keyless daemon a previous run of the app vouched for', function (): void {
    outlivedMarkerUser();

    $port = aSyncPortThisTestOwns();
    $logger = outlivedMarkerLogger();
    $shell = aShellThatRunsWhatItIsAsked();

    outlivedMarkerStore()->forever('sync-listener:credentialled-device', 'desktop-self');

    outlivedMarkerListener(outlivedMarkerStore(), $logger)->startIfEnabled();

    $held = aDaemonHoldingTheSyncPort($port);

    try {
        outlivedMarkerListener(outlivedMarkerStore(), $logger)
            ->startIfEnabled(outlivedMarkerEnvironmentFor('desktop-self'));
    } finally {
        fclose($held);
    }

    expect($logger->said('already listening with these credentials'))->toBeFalse();
    expect($shell->stopped)->toBe(['sync-listener']);
    expect($shell->started[1] ?? null)->toBe(outlivedMarkerEnvironmentFor('desktop-self'));
});

// The same sequence read at the store. A key naming a device is a claim about
// the process now listening, and the process now listening was started without
// one, so the claim has to be gone.
it('forgets the key when it starts a daemon without an identity', function (): void {
    outlivedMarkerUser();

    aSyncPortThisTestOwns();
    aShellThatRunsWhatItIsAsked();

    outlivedMarkerStore()->forever('sync-listener:credentialled-device', 'desktop-self');

    outlivedMarkerListener(outlivedMarkerStore(), outlivedMarkerLogger())->startIfEnabled();

    expect(outlivedMarkerStore()->get('sync-listener:credentialled-device'))->toBeNull();
});

// POSITIVE CONTROL — passes before and after. Unlock hands the credentials over
// every time it happens, and a second unlock must not tear the daemon the first
// one built back down: a pairing ceremony spanning the lock dies with it.
it('leaves the daemon it credentialled alone on the next unlock', function (): void {
    outlivedMarkerUser();

    $port = aSyncPortThisTestOwns();
    $logger = outlivedMarkerLogger();
    $shell = aShellThatRunsWhatItIsAsked();

    outlivedMarkerListener(outlivedMarkerStore(), $logger)->startIfEnabled();

    $held = aDaemonHoldingTheSyncPort($port);

    try {
        outlivedMarkerListener(outlivedMarkerStore(), $logger)
            ->startIfEnabled(outlivedMarkerEnvironmentFor('desktop-self'));

        outlivedMarkerListener(outlivedMarkerStore(), $logger)
            ->startIfEnabled(outlivedMarkerEnvironmentFor('desktop-self'));
    } finally {
        fclose($held);
    }

    expect($logger->said('already listening with these credentials'))->toBeTrue();
    expect($shell->stopped)->toBe(['sync-listener']);
});
