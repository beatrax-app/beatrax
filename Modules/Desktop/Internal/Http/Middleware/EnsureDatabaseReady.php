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
        // Static brand artefacts, served by PHP because no web server sits in
        // front of it here. The desktop setup, staging and welcome shells
        // embed the app mark, and this gate is what shows them.
        'app.icon',
        'app.splash',
        // The guest language switch has to work on the very first screen a
        // fresh install shows, which is the one this gate redirects to.
        'locale.switch',
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
        return $this->firstLaunchRedirect($request) ?? $this->passThrough($request, $next);
    }

    private function firstLaunchRedirect(Request $request): ?RedirectResponse
    {
        return match (true) {
            $this->isExempt($request) => null,
            $this->bootstrap->hasPendingMigrations() => new RedirectResponse($this->urls->route('desktop.setup')),
            $this->bootstrap->isFreshInstall() => new RedirectResponse($this->urls->route('desktop.welcome')),
            default => null,
        };
    }

    private function passThrough(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        $route = $request->route();
        $name = $route === null ? null : $route->getName();
        if (! is_string($name)) {
            return false;
        }

        return $this->hasExemptPrefix($name) || $this->hasExemptSuffix($name);
    }

    private function hasExemptPrefix(string $name): bool
    {
        foreach (self::EXEMPT_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasExemptSuffix(string $name): bool
    {
        foreach (self::EXEMPT_ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
