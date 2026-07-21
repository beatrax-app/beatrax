<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Symfony\Component\HttpFoundation\Response;

// Gates every route behind the first-launch surfaces: pending
// migrations bounce to desktop.setup, a fresh (zero-user) install
// bounces to desktop.welcome. Both first-launch routes plus signup are
// exempt so the redirect cannot loop.
/**
 * @link ../../../../../.docs/features/desktop/architecture.md
 */
final class EnsureDatabaseReady
{
    /** @var array<int, string> */
    private const EXEMPT_ROUTE_PREFIXES = [
        'desktop.setup',
        'desktop.welcome',
        'signup',
        'setup',
        'sw',
        'site.webmanifest',
        'pwa.icon',
    ];

    /** @var array<int, string> */
    private const EXEMPT_ROUTE_SUFFIXES = [
        'livewire.update',
    ];

    public function __construct(
        private readonly FirstLaunchBootstrap $bootstrap,
        private readonly UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if ($this->bootstrap->hasPendingMigrations()) {
            return new RedirectResponse($this->urls->route('desktop.setup'));
        }

        if ($this->bootstrap->isFreshInstall()) {
            return new RedirectResponse($this->urls->route('desktop.welcome'));
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        $route = $request->route();
        if ($route === null) {
            return false;
        }

        $name = $route->getName();
        if (! is_string($name)) {
            return false;
        }

        foreach (self::EXEMPT_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        foreach (self::EXEMPT_ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
