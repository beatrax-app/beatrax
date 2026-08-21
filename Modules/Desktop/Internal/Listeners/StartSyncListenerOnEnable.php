<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Modules\Desktop\Internal\Native\RelayListenerProcess;
use Modules\Desktop\Internal\Native\SyncListenerProcess;
use Modules\Sync\Public\Events\DeviceSyncEnabled;
use Modules\Sync\Public\Events\SyncTransportCredentialsAvailable;
use Modules\Sync\Public\Services\SyncDaemonIdentity;

// Boot only starts the daemon for a device that already had an identity, so
// without this the device stays unreachable until the next launch.
final readonly class StartSyncListenerOnEnable
{
    // The identity reader is resolved on demand, never injected: taking it in the
    // constructor pulled DeviceIdentityLoader into the container the moment sync
    // was enabled, freezing whichever AppLockKeyService was bound at that instant.
    public function __construct(
        private SyncListenerProcess $listener,
        private RelayListenerProcess $relay,
        private Container $container,
    ) {}

    public function handle(DeviceSyncEnabled $event): void
    {
        // An unlocked request is the only context that can open the sealed identity;
        // the daemon cannot read it itself, so it is handed over here or the
        // listener answers handshakes with no key at all.
        $this->listener->startIfEnabled($this->environmentFor($event->userId) ?? []);

        // Pairing frames only travel over the relay, so the very next thing the user
        // does — pair a phone — would have no transport.
        $this->relay->startIfEnabled();
    }

    // DeviceSyncEnabled never fires again for a device enabled on an earlier
    // launch, so without this its daemon keeps the keyless state it booted with.
    public function handleCredentialsAvailable(SyncTransportCredentialsAvailable $event): void
    {
        $environment = $this->environmentFor($event->userId);

        if ($environment === null) {
            return;
        }

        $this->listener->startIfEnabled($environment);
        $this->relay->startIfEnabled();
    }

    /**
     * @return array<string, string>|null
     */
    private function environmentFor(int $userId): ?array
    {
        return $this->container->make(SyncDaemonIdentity::class)
            ->env($userId, $this->container->make(Session::class));
    }
}
