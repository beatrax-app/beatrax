<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// This runtime boots once per process, so the `auth` singleton keeps the User
// model resolved at sign-in — and every preference lives on that model, so a
// saved one was written and then read back stale until a force-quit. Core's
// ClearGuardBetweenJobs needs the same thing for the same reason.
final readonly class ForgetGuardsBetweenRequests
{
    public function __construct(private AuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The USER is dropped, not the guard. forgetGuards() rebuilds the
        // session driver, and rebuilding registers a rebound callback that is
        // never pruned — on a runtime that never restarts, that grows without
        // bound and every later request walks the whole list.
        $guard = $this->auth->guard();

        if ($guard instanceof SessionGuard && $this->canBeResolvedAgain($guard)) {
            $guard->forgetUser();
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // Dropping only refreshes a user the session can name again. An identity
    // bound straight onto the guard has nothing behind it, so dropping that
    // one signs the caller out instead — the same distinction that keeps
    // ClearGuardBetweenJobs off the sync queue driver.
    private function canBeResolvedAgain(SessionGuard $guard): bool
    {
        return $guard->getSession()->has($guard->getName());
    }
}
