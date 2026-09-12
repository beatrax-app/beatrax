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
use Modules\Sync\Public\Testing\DeviceIdentityTestHarness;
use Native\Desktop\Contracts\ChildProcess as ChildProcessContract;
use Native\Desktop\Facades\ChildProcess;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

afterEach(function (): void {
    DeviceIdentityTestHarness::forgetAll();
});

// Measured on the desktop install on 2026-09-11. The daemon holding :51337 had
// neither BEATRAX_SYNC_USER_ID nor BEATRAX_SYNC_DEVICE_ID, and the shell's own
// record for the alias — GET child-process/get/sync-listener — carried an env
// with no BEATRAX_SYNC_* key in it at all. The restart meant to replace it
// logged one caught throw and the app carried on as though sync worked.

function restartListenerLogger(): object
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

function restartListenerUser(): User
{
    $user = User::query()->create([
        'username' => 'listener-restart-'.bin2hex(random_bytes(4)),
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
function restartDaemonEnvironmentFor(string $deviceId): array
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
// under test must see portIsBound() answer true so the reconcile path runs.
function withTheRestartPortHeld(Closure $work): void
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

// The shell claims an alias BEFORE the process it belongs to has exited: stop
// only signals, and the entry is deleted on the exit event. A start arriving in
// that window is answered with the entry already registered — the PREVIOUS,
// keyless settings — so its answer is no evidence that anything new ran.
/** @param  array<string, string>|null  $answersWith */
function aShellThatAnswersStartsWith(?array $answersWith, bool $throwsInstead = false): object
{
    $double = new class implements ChildProcessContract
    {
        /** @var array<string, string>|null */
        public ?array $env = null;

        public bool $throws = false;

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
            if ($this->throws) {
                throw new ErrorException('Trying to access array offset on null');
            }

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

    $double->env = $answersWith;
    $double->throws = $throwsInstead;

    ChildProcess::swap($double);

    return $double;
}

function listenerProcessWith(CacheRepository $cache, object $logger): SyncListenerProcess
{
    return new SyncListenerProcess(
        app(DeviceRegistryService::class),
        app(SyncPorts::class),
        $logger,
        $cache,
    );
}

// THE DEFECT. The shell answered the start with the keyless environment the
// alias already held, and the app wrote the offered device id down as the one
// now running — after which every reconcile reads "already listening with these
// credentials" and never tries again.
it('does not record credentials the shell never reported back', function (): void {
    restartListenerUser();

    $cache = new CacheRepository(new ArrayStore);
    $logger = restartListenerLogger();

    aShellThatAnswersStartsWith(['APP_ENV' => 'local']);

    withTheRestartPortHeld(function () use ($cache, $logger): void {
        listenerProcessWith($cache, $logger)
            ->startIfEnabled(restartDaemonEnvironmentFor('the-freshly-unlocked-device'));
    });

    expect($cache->get('sync-listener:credentialled-device'))->toBeNull();
    expect($logger->said('without the device credentials'))->toBeTrue();
});

// POSITIVE CONTROL — passes before and after. A shell that really did start the
// daemon with the environment it was handed is still recorded as a success, so
// the assertion above discriminates rather than refusing every restart.
it('records credentials the shell did report back', function (): void {
    restartListenerUser();

    $cache = new CacheRepository(new ArrayStore);
    $logger = restartListenerLogger();

    aShellThatAnswersStartsWith(restartDaemonEnvironmentFor('the-freshly-unlocked-device'));

    withTheRestartPortHeld(function () use ($cache, $logger): void {
        listenerProcessWith($cache, $logger)
            ->startIfEnabled(restartDaemonEnvironmentFor('the-freshly-unlocked-device'));
    });

    expect($cache->get('sync-listener:credentialled-device'))->toBe('the-freshly-unlocked-device');
    expect($logger->said('without the device credentials'))->toBeFalse();
});

// NativePHP answers a start it cannot serve with a body that is not a process,
// and the facade reads that as an array offset on null — the ErrorException
// this install logged verbatim. A throw is a start that did not happen.
it('does not record credentials when the start throws', function (): void {
    restartListenerUser();

    $cache = new CacheRepository(new ArrayStore);
    $logger = restartListenerLogger();

    aShellThatAnswersStartsWith(null, throwsInstead: true);

    withTheRestartPortHeld(function () use ($cache, $logger): void {
        listenerProcessWith($cache, $logger)
            ->startIfEnabled(restartDaemonEnvironmentFor('the-freshly-unlocked-device'));
    });

    expect($cache->get('sync-listener:credentialled-device'))->toBeNull();
    expect($logger->said('without the device credentials'))->toBeTrue();
});
