<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The mobile runtime boots the application ONCE per process and dispatches every
// later request into it, so a container singleton outlives the request that
// resolved it. `auth` is one, and its guard holds the User model it resolved at
// sign-in — the same instance, for the life of the app.
//
// Every preference lives on that model. So saving one wrote the new value to the
// database and then went on reading the old one: the language switched, the next
// screen switched back, and only a force-quit made it stick. Theme did the same,
// and so does anything else on `users`.
//
// Dropping the resolved guards makes the next read of the user go back to the
// session, which is what a per-request container does for free everywhere else.
// The queue worker needs exactly this for exactly this reason — see
// Core's ClearGuardBetweenJobs, which clears rather than restores for the same
// argument: a restore is something a caller can silently forget to do.
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
