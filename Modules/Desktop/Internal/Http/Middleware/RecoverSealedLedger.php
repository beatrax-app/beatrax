<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SealedLedgerRecovery;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// A web request is the only place on this device where the app-lock key is
// held: `sync:serve` drains peer frames from a console daemon that has no
// session and never will, and the queue worker is in the same position. So the
// recovery those two cannot perform is picked up by the next request that can.
/**
 * @link ../../../../../.docs/features/sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee
 */
final readonly class RecoverSealedLedger
{
    public function __construct(
        private CurrentUser $currentUser,
        private SealedLedgerRecovery $recovery,
        private SessionFactory $session,
        private LoggerInterface $log,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // terminate(), so a full history re-projection is paid after the response
    // has gone out rather than in front of a page the user is waiting for.
    public function terminate(Request $request, Response $response): void
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
