<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Middleware;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Middleware\AfterResponseMiddleware;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\OpLog\DeferredOpCaptureDrain;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Psr\Log\LoggerInterface;
use Throwable;

// Where the mutations a scheduler, a daemon or a locked screen could not sign
// finally reach the log. Signing needs the app-lock key, which lives in a
// session and nowhere else, so an unlocked request is the only process that can
// do this — the same bargain the pairing courier and the capture resume make.
/**
 * @link ../../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md#the-drain
 */
final readonly class DrainsDeferredOpCaptures extends AfterResponseMiddleware
{
    private const string THROTTLE_KEY = 'sync:deferred-op-capture-drain:';

    // The queue reader is safe to hold — it reaches the database and the clock,
    // which is the whole of the idle path below. The drain is resolved on
    // demand, because it binds a writer that must not outlive its request.
    public function __construct(
        private CurrentUser $currentUser,
        private DeferredOpCaptures $queue,
        private Container $container,
        private Cache $cache,
        private LoggerInterface $log,
    ) {}

    protected function afterResponse(): void
    {
        try {
            $this->drain();
        } catch (Throwable $e) {
            // A drain is a retry by nature: the coordinates are still there and
            // the next request takes them again. What it must never do is turn
            // a page into a 500 over work the reader did not ask for.
            $this->log->warning('DrainsDeferredOpCaptures: drain failed.', SafeExceptionContext::describe($e));
        }
    }

    private function drain(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        $userId = $this->currentUser->id();

        // One covered index read, and with nothing owed — which is the resting
        // state of every device that is not locked for long — that read is the
        // whole cost: no throttle marker, no writer built, no row touched.
        if (! $this->queue->hasPending($userId)) {
            return;
        }

        if (! $this->cache->add(self::THROTTLE_KEY.$userId, true, TailSweepInterval::SECONDS)) {
            return;
        }

        $this->container->make(DeferredOpCaptureDrain::class)->drain($userId);
    }
}
