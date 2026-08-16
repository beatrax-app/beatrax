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

// Brings the sync listener up the moment sync is enabled, so the device is
// reachable without a restart. Boot only starts the daemon for a device that
// already had an identity, which is every launch after this one.
/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final readonly class StartSyncListenerOnEnable
{
    // The identity reader is resolved on demand, never injected: taking it
    // in the constructor pulled DeviceIdentityLoader into the container the
    // moment sync was enabled, freezing whichever AppLockKeyService happened
    // to be bound at that instant.
    public function __construct(
        private SyncListenerProcess $listener,
        private RelayListenerProcess $relay,
        private Container $container,
    ) {}

    public function handle(DeviceSyncEnabled $event): void
    {
        // This runs in an unlocked request, the only context that can open the
        // sealed identity. The daemon cannot read it itself, so it is handed
        // over here or the listener answers handshakes with no key at all.
        $this->listener->startIfEnabled($this->environmentFor($event->userId) ?? []);

        // Pairing frames only travel over the relay, so enabling sync has to
        // bring it up in the same session — otherwise the very next thing the
        // user does (pair a phone) has no transport.
        $this->relay->startIfEnabled();
    }

    // Same handoff for a device whose sync was enabled on an earlier launch:
    // DeviceSyncEnabled never fires again, so without this its daemon keeps
    // the keyless state it booted with.
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
