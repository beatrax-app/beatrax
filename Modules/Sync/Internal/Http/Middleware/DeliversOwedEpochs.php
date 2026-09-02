<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Middleware;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Middleware\AfterResponseMiddleware;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Psr\Log\LoggerInterface;
use Throwable;

// The fourth delivery leg: repays a fan-out the pairing screen never reached.
// Sealing needs the app-lock key, which no daemon and no queue worker holds, so
// the only thing that can carry it is another request — the same bargain
// ResumesPreSyncCapture makes beside this file.
/**
 * @link ../../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md#leg-4-repay-a-fan-out-the-screen-never-reached
 */
final readonly class DeliversOwedEpochs extends AfterResponseMiddleware
{
    private const int TICK_INTERVAL_SECONDS = 5;

    private const string THROTTLE_KEY = 'sync:owed-epochs:';

    public function __construct(
        private CurrentUser $currentUser,
        private GdkRotationService $rotation,
        private Container $container,
        private Cache $cache,
        private LoggerInterface $log,
    ) {}

    protected function afterResponse(): void
    {
        try {
            $this->deliver();
        } catch (Throwable $e) {
            // A debt is a retry by nature: the next request tries again. What
            // it must never do is turn a page into a 500 over work the reader
            // did not ask for.
            $this->log->warning('DeliversOwedEpochs: delivery failed.', SafeExceptionContext::describe($e));
        }
    }

    private function deliver(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        $userId = $this->currentUser->id();

        // One covered lookup on a table holding a handful of rows, and with
        // nothing owed — which is every request after the first — that read is
        // the whole cost: no throttle marker, no keyring opened, no wrap built.
        $owed = $this->rotation->peersOwedEpochs($userId);

        if ($owed === []) {
            return;
        }

        if (! $this->cache->add(self::THROTTLE_KEY.$userId, true, self::TICK_INTERVAL_SECONDS)) {
            return;
        }

        $session = $this->container->make(Session::class);

        foreach ($owed as $deviceRegistryId) {
            // Per peer, so one device whose key material cannot be sealed does
            // not hold up the rest. loadKeyring() throws when the app-lock key
            // is not held, which is an ordinary locked request, not a fault.
            try {
                $this->rotation->fanOutAllEpochsToDevice($userId, $deviceRegistryId, $session);
            } catch (Throwable $e) {
                $this->log->info('DeliversOwedEpochs: a peer is still owed its epochs.', [
                    'device_registry_id' => $deviceRegistryId,
                    ...SafeExceptionContext::describe($e),
                ]);

                return;
            }
        }
    }
}
