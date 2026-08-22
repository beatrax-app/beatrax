<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Pairing\PairingTokenRowReader;
use Modules\Sync\Internal\Pairing\PendingPairingCourier;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// The courier's driver on a device that runs no daemon. A phone has no cron, no
// queue worker and no listener, so the only thing that reliably runs there is a
// request — and every request, from every screen, drives this. It is also the
// only place holding the app-lock key, so the signing half runs here or nowhere.
/**
 * @link ../../../../../.docs/features/sync/pairing-handshake.md#redelivery-must-not-depend-on-an-open-screen
 */
final readonly class CarriesPendingPairingFrames
{
    // Matches the pairing screen's own poll, so a ceremony costs what it always
    // cost. The window is what stops a screen polling several times a second
    // from turning into several browses and several relay round trips.
    private const int TICK_INTERVAL_SECONDS = 3;

    private const string THROTTLE_KEY = 'sync:pending-pairing-courier:';

    // Resolved on demand, never injected: this middleware is built on EVERY web
    // request, and taking the courier here would freeze whichever RelayClient
    // and RelayConfig existed at the first one. The row reader is safe to hold —
    // it reaches the database and the clock, the whole of the idle path below.
    public function __construct(
        private CurrentUser $currentUser,
        private PairingTokenRowReader $rows,
        private Container $container,
        private SessionFactory $session,
        private Cache $cache,
        private LoggerInterface $log,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // terminate(), so a browse that burns its full timeout and a relay round
    // trip are paid after the response has gone out rather than in front of a
    // page somebody is waiting for.
    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->carry();
        } catch (Throwable $e) {
            // Redelivery is a retry by nature: the next request tries again.
            // What it must never do is turn a page into a 500 over work the
            // reader did not ask for.
            $this->log->warning('CarriesPendingPairingFrames: courier tick failed.', SafeExceptionContext::describe($e));
        }
    }

    private function carry(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        $userId = $this->currentUser->id();

        // One covered index read, then the throttle marker. With no ceremony in
        // flight — which is nearly always — this costs that read alone: nothing
        // is written, nothing is resolved, and no transport is touched.
        if (! $this->rows->hasLiveHandshake($userId)) {
            return;
        }

        if (! $this->cache->add(self::THROTTLE_KEY.$userId, true, self::TICK_INTERVAL_SECONDS)) {
            return;
        }

        // Null while the app is locked. The collecting half still runs on that
        // identity-free path, which is the same bargain the daemon makes.
        $identity = $this->container->make(DeviceIdentityLoader::class)
            ->load($userId, ($this->session)());

        $this->container->make(PendingPairingCourier::class)->tick($userId, $identity);
    }
}
