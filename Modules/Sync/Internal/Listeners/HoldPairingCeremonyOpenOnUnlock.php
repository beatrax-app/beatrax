<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners;

use Illuminate\Contracts\Container\Container;
use Modules\Auth\Public\Events\AppLockUnlocked;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-pairing-outlives-the-lock-that-interrupts-it
 */
final readonly class HoldPairingCeremonyOpenOnUnlock
{
    // PairingGateway is resolved on demand, never injected. Taking it here built
    // the whole pairing graph on the FIRST unlock, and those are singletons: a
    // relay configured later in the same run — exactly what a scanned QR does —
    // was then invisible to the frozen courier.
    public function __construct(
        private Container $container,
        private CurrentUser $currentUser,
        private LoggerInterface $log,
    ) {}

    public function handle(AppLockUnlocked $event): void
    {
        // An enclave recovery and the test harness both unlock a session that
        // no guard has a user bound to; there is no ceremony to find without
        // one, and asking would throw out of an already-completed unlock.
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        try {
            $this->container->make(PairingGateway::class)
                ->holdCeremonyOpenAcrossLock($this->currentUser->user()->id, $event->session);
        } catch (\Throwable $e) {
            // Never-throw, for the reason the passphrase-change listener is:
            // the unlock is already done, and a pairing row that could not be
            // revived must not become an exception on the lock screen.
            $this->log->warning('HoldPairingCeremonyOpenOnUnlock: could not extend the ceremony', [
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }
}
