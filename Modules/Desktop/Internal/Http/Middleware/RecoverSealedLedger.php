<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Middleware;

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Middleware\AfterResponseMiddleware;
use Modules\Core\Public\Services\SealedLedgerRecovery;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// A web request is the only place on this device where the app-lock key is
// held: `sync:serve` drains peer frames from a console daemon that has no
// session and never will, and the queue worker is in the same position. So the
// recovery those two cannot perform is picked up by the next request that can.
/**
 * @link ../../../../../.docs/features/sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee
 */
final readonly class RecoverSealedLedger extends AfterResponseMiddleware
{
    public function __construct(
        private CurrentUser $currentUser,
        private SealedLedgerRecovery $recovery,
        private SessionFactory $session,
        private LoggerInterface $log,
    ) {}

    // After the response rather than in front of it, so a full history
    // re-projection is not paid ahead of a page the user is waiting for.
    protected function afterResponse(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        try {
            $this->recovery->recover($this->currentUser->id(), ($this->session)());
        } catch (Throwable $e) {
            // A failed pass leaves both markers unmoved, so the next request
            // retries. What it must not do is turn somebody's page into a 500
            // over work they never asked for.
            $this->log->warning('RecoverSealedLedger: recovery pass failed.', SafeExceptionContext::describe($e));
        }
    }
}
