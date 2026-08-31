<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Http\Middleware;

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Middleware\AfterResponseMiddleware;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Notifications\Internal\Support\DeferredNotificationPasses;
use Psr\Log\LoggerInterface;
use Throwable;

// An unlocked request is the only process on a phone that holds the app-lock
// key: the OS scheduler cold-starts, and the queue worker it drains builds its
// own empty session. So the notification passes those two cannot seal are
// re-derived here, by the next request that can.
/**
 * @link ../../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
final readonly class RunDeferredNotificationPasses extends AfterResponseMiddleware
{
    public function __construct(
        private CurrentUser $currentUser,
        private DeferredNotificationPasses $passes,
        private SessionFactory $session,
        private LoggerInterface $log,
    ) {}

    // After the response, never in front of it. The unlock is the one
    // interaction that has to feel instant, and it is also the first request
    // that can run any of this — so the two would collide on every open.
    protected function afterResponse(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        try {
            $this->passes->runOutstanding($this->currentUser->id(), ($this->session)());
        } catch (Throwable $e) {
            // The marks of any pass that did not finish are still standing, so
            // the next request takes them again. What this must not do is turn
            // a page somebody asked for into a 500 over work they did not.
            $this->log->warning(
                'RunDeferredNotificationPasses: a deferred pass did not complete.',
                SafeExceptionContext::describe($e),
            );
        }
    }
}
