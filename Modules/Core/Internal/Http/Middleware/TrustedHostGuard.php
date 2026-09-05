<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Internal\Support\NetworkBoundary;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class TrustedHostGuard
{
    public function __construct(private NetworkBoundary $boundary) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAllowedHost($request->getHost())) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // LoopbackOnly gates the interface the connection arrived on; this gates
    // the name the client asked for. The gap it closes is DNS rebinding: a
    // site that repoints its own domain at 127.0.0.1 reaches a genuinely
    // loopback socket, so only the attacker-controlled Host gives it away.
    private function isAllowedHost(string $host): bool
    {
        // No Host at all is the in-process shell, not an attacker. Rebinding
        // works by NAMING a domain, and every browser sends Host on HTTP/1.1,
        // so an empty one cannot carry the attack this gate exists to stop —
        // while LoopbackOnly still gates the interface it arrived on.
        if ($host === '') {
            return true;
        }

        // The same object LoopbackOnly asks about interfaces answers this,
        // so the two halves cannot drift into allowing different things: the
        // host baked into APP_URL is both what this admits and what the other
        // reads a widened install's remote requests against.
        return in_array(strtolower($host), $this->boundary->allowedHosts(), true);
    }
}
