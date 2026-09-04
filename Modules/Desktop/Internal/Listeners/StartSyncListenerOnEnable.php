<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Events\AppLockUnlocked;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Desktop\Internal\Native\RelayListenerProcess;
use Modules\Desktop\Internal\Native\SyncListenerProcess;
use Modules\Sync\Public\Events\DeviceSyncEnabled;
use Modules\Sync\Public\Events\SyncTransportCredentialsAvailable;
use Modules\Sync\Public\Services\SyncDaemonIdentity;
use Psr\Log\LoggerInterface;
use Throwable;

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
        private CurrentUser $currentUser,
        private LoggerInterface $log,
    ) {}

    // The unlock is the ONLY moment the sealed identity becomes openable, and the
    // daemon was already spawned — keyless — at app boot. Credentialling it here
    // rather than when a pairing modal happens to open is what makes the desktop
    // discoverable at all (see @link).
    /**
     * @link ../../../../.docs/features/sync/pairing-handshake.md#a-pairing-outlives-the-lock-that-interrupts-it
     */
    public function handleUnlocked(AppLockUnlocked $event): void
    {
        // An enclave recovery and the test harness both unlock a session no guard
        // has a user bound to; asking for one would throw out of a completed unlock.
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        try {
            $environment = $this->environmentFor($this->currentUser->user()->id, $event->session);

            if ($environment === null) {
                return;
            }

            $this->listener->startIfEnabled($environment);
            $this->relay->startIfEnabled();
        } catch (Throwable $e) {
            // Never-throw: the unlock has already happened, and a listener that
            // could not be credentialled must not become an exception on the
            // lock screen the reader just cleared.
            $this->log->warning('StartSyncListenerOnEnable: could not credential the listener on unlock', [
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }

    public function handle(DeviceSyncEnabled $event): void
    {
        // An unlocked request is the only context that can open the sealed identity;
        // the daemon cannot read it itself, so it is handed over here or the
        // listener answers handshakes with no key at all.
        $environment = $this->environmentFor($event->userId);

        // The request that turns sync ON is unlocked by definition, so an
        // identity that will not open here is a failure and not the locked
        // state the empty array stands for. Spawning on it starts a daemon
        // that refuses every peer, which on screen is sync that never begins.
        if ($environment === null) {
            $this->log->warning('StartSyncListenerOnEnable: refused to start the listener without an identity', [
                'user_id' => $event->userId,
            ]);
        } else {
            $this->listener->startIfEnabled($environment);
        }

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
    private function environmentFor(int $userId, ?Session $session = null): ?array
    {
        return $this->container->make(SyncDaemonIdentity::class)
            ->env($userId, $session ?? $this->container->make(Session::class));
    }
}
