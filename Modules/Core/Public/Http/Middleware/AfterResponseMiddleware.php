<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Laravel decides a middleware is terminable by finding a terminate() method on
// it, then calls that method with the request and the response it has already
// sent. Deferred work uses neither, so the signature the kernel imposes is
// declared once here instead of being restated by every middleware that defers.
abstract readonly class AfterResponseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->afterResponse();
    }

    abstract protected function afterResponse(): void;
}
