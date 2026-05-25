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
 * Gates the application routes behind the first-launch surfaces (D-21 / D-22).
 *
 * Two redirect predicates, evaluated in order:
 *
 *   1. `FirstLaunchBootstrap::hasPendingMigrations()` — the migration runner
 *      has work outstanding. The user is bounced to `desktop.setup` so the
 *      "Setting up…" splash is visible while the bootstrap finishes. This
 *      case is rare in practice (the NativePHP provider's `boot()` already
 *      runs the migrator before any HTTP request) but stays in place for
 *      upgrade-shipped migrations that absorb mid-launch.
 *
 *   2. `FirstLaunchBootstrap::isFreshInstall()` — migrations have run, the
 *      `users` table is empty. This is the only reliable post-migration
 *      signal that no account has been created on this device, so it is
 *      what gates the welcome screen. The user is bounced to
 *      `desktop.welcome`, which leads into `/signup` on the "Get started"
 *      click.
 *
 * Both first-launch routes themselves — plus the signup ceremony they lead
 * into — are exempted from the gate so the redirect cannot loop. The exempt
 * list is name-prefix-matched so future extra-safe URLs (an error variant of
 * the setup screen, for example) inherit the exemption automatically.
 *
 * The Livewire AJAX update endpoint is also exempted. Livewire registers a
 * `web`-group POST route (`*livewire.update`) that every component update
 * funnels through — including the signup form's `submit` call. Without the
 * exemption the gate would short-circuit the AJAX POST that is supposed to
 * CREATE the first user, returning a 302 to /welcome before SignupAction
 * runs. The component's own host-route name (`signup`) is exempt, but the
 * AJAX endpoint has its own distinct route name that needs its own match.
 */
final class EnsureDatabaseReady
{
    /**
     * Route-name prefixes this middleware never redirects away from. The
     * gated-state-rendering surfaces (setup + welcome) match themselves,
     * and the first-user signup ceremony is included so the welcome →
     * signup chain does not bounce back to welcome before the user can
     * create the first account.
     *
     * `setup` covers the post-signup first-run wizard surface — the
     * Onboarding module's `/setup` route gates its own first-launch UX
     * (the user is already past welcome by then) and must not be
     * bounced back to the welcome screen mid-wizard.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTE_PREFIXES = [
        'desktop.setup',
        'desktop.welcome',
        'signup',
        'setup',
    ];

    /**
     * Route-name suffixes this middleware never redirects away from. The
     * Livewire AJAX update endpoint is named `default-livewire.update`
     * (or `<prefix>.livewire.update` when an app registers a custom one);
     * Livewire's own router check uses the `*livewire.update` wildcard,
     * so a suffix match here is the symmetric exemption. Without it, the
     * signup form's Livewire POST is funneled into the fresh-install gate
     * before it can create the first user.
     *
     * @var array<int, string>
     */
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
