<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\SyncListenerProcess;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SyncPorts;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

// Captures whether the gate declined, without asserting on a mocked facade:
// the "not starting" line is only ever emitted on the declining branch.
function syncListenerSpyLogger(): object
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

        public function declined(): bool
        {
            foreach ($this->messages as $message) {
                if (str_contains($message, 'not starting')) {
                    return true;
                }
            }

            return false;
        }
    };
}

function seedDesktopSelfDevice(int $userId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toIso8601String();

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
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

// The daemon used to start on every desktop launch. With no device identity
// nothing could dial in, so the app bound a socket for a feature the user may
// never turn on, and a crash-looping listener filled the log of an idle app.
it('does not start the listener on a device that has never enabled sync', function (): void {
    $logger = syncListenerSpyLogger();

    $process = new SyncListenerProcess(
        app(DeviceRegistryService::class),
        app(SyncPorts::class),
        $logger,
    );

    $process->startIfEnabled();

    expect($logger->declined())->toBeTrue();
});

it('starts the listener once a device identity exists', function (): void {
    $user = User::query()->create([
        'username' => 'sync-listener-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-this-gate'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    seedDesktopSelfDevice((int) $user->id);

    $logger = syncListenerSpyLogger();

    $process = new SyncListenerProcess(
        app(DeviceRegistryService::class),
        app(SyncPorts::class),
        $logger,
    );

    $process->startIfEnabled();

    // Whether the child process spawns is NativePHP's business and unavailable
    // outside the desktop runtime; the failure is caught and logged, not thrown.
    expect($logger->declined())->toBeFalse();
});

it('reports a self device only when one is registered', function (): void {
    $registry = app(DeviceRegistryService::class);

    expect($registry->hasLocalDevice())->toBeFalse();

    $user = User::query()->create([
        'username' => 'sync-registry-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-this-gate'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    seedDesktopSelfDevice((int) $user->id);

    expect($registry->hasLocalDevice())->toBeTrue();
});

it('leaves a listener that is already holding the port alone', function (): void {
    // A persistent ChildProcess outlives the Electron process that spawned it,
    // so a crash-and-relaunch finds the previous listener still bound and a
    // second start fatals with "Address already in use".
    $user = User::query()->create([
        'username' => 'sync-port-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-this-gate'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    seedDesktopSelfDevice((int) $user->id);

    // Stand in for the stale process by binding the port this test's run owns.
    $stale = @stream_socket_server('tcp://127.0.0.1:51337', $errno, $errstr);

    if ($stale === false) {
        $this->markTestSkipped('port 51337 unavailable in this environment');
    }

    $logger = syncListenerSpyLogger();

    try {
        (new SyncListenerProcess(app(DeviceRegistryService::class), app(SyncPorts::class), $logger))->startIfEnabled();
    } finally {
        fclose($stale);
    }

    $stoodDown = false;
    foreach ($logger->messages as $message) {
        if (str_contains($message, 'already listening')) {
            $stoodDown = true;
        }
    }

    expect($stoodDown)->toBeTrue();
});
