<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Middleware;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Middleware\AfterResponseMiddleware;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\OpLog\BackfillProgress;
use Modules\Sync\Internal\OpLog\PreSyncHistoryCapture;
use Psr\Log\LoggerInterface;
use Throwable;

// The driver for a capture that could not finish in the request that started
// it. Signing needs the app-lock key, which no daemon and no queue worker
// holds, so the only thing that can carry the rest of the walk is another
// request — the same bargain the pairing courier makes one file over.
/**
 * @link ../../../../../.docs/features/sync/pre-sync-history-capture.md#the-driver-is-a-request-because-nothing-else-holds-the-key
 */
final readonly class ResumesPreSyncCapture extends AfterResponseMiddleware
{
    private const string THROTTLE_KEY = 'sync:pre-sync-capture:';

    // The progress reader is safe to hold — it reaches the database and the
    // clock, which is the whole of the idle path below. The capture itself is
    // resolved on demand, because it binds a writer that must not outlive the
    // request that built it.
    public function __construct(
        private CurrentUser $currentUser,
        private BackfillProgress $progress,
        private Container $container,
        private Cache $cache,
        private LoggerInterface $log,
    ) {}

    protected function afterResponse(): void
    {
        try {
            $this->resume();
        } catch (Throwable $e) {
            // A capture is a retry by nature: the next request tries again.
            // What it must never do is turn a page into a 500 over work the
            // reader did not ask for.
            $this->log->warning('ResumesPreSyncCapture: resume failed.', SafeExceptionContext::describe($e));
        }
    }

    private function resume(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        $userId = $this->currentUser->id();

        // One covered lookup on a table holding at most one row per user, and
        // with nothing owed — which is nearly always — that read is the whole
        // cost: no throttle marker written, no writer built, no walk started.
        if (! $this->progress->isOpen($userId)) {
            return;
        }

        if (! $this->cache->add(self::THROTTLE_KEY.$userId, true, TailSweepInterval::SECONDS)) {
            return;
        }

        $this->container->make(PreSyncHistoryCapture::class)->resume($userId);
    }
}
