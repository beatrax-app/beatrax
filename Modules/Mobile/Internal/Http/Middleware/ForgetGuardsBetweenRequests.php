<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
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
        // Global middleware, so this runs before the session is even started;
        // the guard it drops is the one left behind by the PREVIOUS request,
        // and the next read rebuilds it from this request's session.
        $this->auth->forgetGuards();

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
