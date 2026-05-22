<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the application routes behind a "DB is ready" check (D-21).
 *
 * When `FirstLaunchBootstrap::hasPendingMigrations()` is true, the
 * middleware redirects every request to the `desktop.setup` route so the
 * "Setting up…" screen is visible while the bootstrap finishes its work.
 * Once migrations are no longer pending the middleware is a pass-through.
 *
 * The setup route itself is exempt — without that carve-out the redirect
 * would loop. The exemption is keyed on the route name so future
 * extra-safe URLs (an error variant of the setup screen, for example) can
 * be added by registering them under the same name prefix.
 */
final class EnsureDatabaseReady
{
    /**
     * Route names this middleware never redirects away from — they are
     * the routes that *render* the gated state.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTE_NAMES = [
        'desktop.setup',
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

        return is_string($name) && in_array($name, self::EXEMPT_ROUTE_NAMES, true);
    }
}
