<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class TrustedHostGuard
{
    public function __construct(private Repository $config) {}

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

        return in_array(strtolower($host), $this->allowedHosts(), true);
    }

    // The bundled desktop and mobile shells load the app over loopback names,
    // and a self-hoster reaches it under the host baked into APP_URL. A Host
    // outside that set — an attacker-controlled domain rebound to loopback is
    // the case that matters — is one this server does not serve.
    /** @return list<string> */
    private function allowedHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', '::1', '[::1]'];

        $configured = $this->config->get('app.url');
        $host = is_string($configured) ? parse_url($configured, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            $hosts[] = strtolower($host);
        }

        return $hosts;
    }
}
