<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Listeners\StartSyncListenerOnEnable;
use Modules\Desktop\Internal\Native\RelayListenerProcess;
use Modules\Desktop\Internal\Native\SyncListenerProcess;
use Modules\Sync\Public\Events\DeviceSyncEnabled;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\LocalRelayProvisioner;
use Modules\Sync\Public\Services\SyncPorts;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

// SyncDaemonIdentity::env() answers null when the sealed identity cannot be
// opened, and says what a caller must then do: leave the daemon as it is rather
// than restart it into a state that rejects every peer. Two of the three
// listeners here obeyed that; the one that runs when a reader turns sync ON
// passed `?? []` and spawned a daemon with no key, which answers every
// handshake with a refusal and reads on screen as sync that never begins.
function spawnGuardLogger(): object
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

it('refuses to spawn the listener when the identity will not open, and says so', function (): void {
    $user = User::query()->create([
        'username' => 'spawn-guard-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $logger = spawnGuardLogger();

    $listener = new StartSyncListenerOnEnable(
        new SyncListenerProcess(
            app(DeviceRegistryService::class),
            app(SyncPorts::class),
            $logger,
            new CacheRepository(new ArrayStore),
        ),
        new RelayListenerProcess(
            app(DeviceRegistryService::class),
            app(LocalRelayProvisioner::class),
            app(SyncPorts::class),
            $logger,
        ),
        $this->app,
        app(Modules\Core\Public\Contracts\CurrentUser::class),
        $logger,
    );

    $listener->handle(new DeviceSyncEnabled((int) $user->id));

    // The second half is what makes this more than a log assertion: reaching
    // SyncListenerProcess at all is what leaves its own "not starting" line,
    // so its absence is the spawn attempt not having been made.
    expect($logger->said('refused to start the listener without an identity'))->toBeTrue()
        ->and($logger->said('no device identity on this device'))->toBeFalse();
});
