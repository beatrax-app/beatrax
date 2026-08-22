<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md#the-session-store-outlives-the-request-that-filled-it
 */
final readonly class ForgetStaleSessionBetweenRequests
{
    public function __construct(private Session $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        // save() ends a request by writing the attributes out and clearing the
        // started flag, never the attributes; start() then array_replace()s the
        // incoming id's row OVER them. Started means the caller filled this
        // session before the request rather than during the last one.
        if (! $this->session->isStarted()) {
            $this->session->flush();
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
