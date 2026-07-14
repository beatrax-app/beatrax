<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the mobile app's routes behind the first-launch welcome surface —
 * the mobile mirror of `Modules\Desktop\Internal\Http\Middleware\
 * EnsureDatabaseReady`.
 *
 * A single redirect predicate: `MobileFirstLaunchBootstrap::isFreshInstall()`
 * — the `users` table is empty. Migrations themselves are NOT this
 * middleware's concern on mobile: the mobile root's `->booted()` hook
 * (`mobile-app/bootstrap/app.php`) already runs the framework migrator via
 * the same `MobileFirstLaunchBootstrap` collaborator before any HTTP
 * request reaches the router (15-04-PLAN.md), so by the time this gate
 * evaluates, migrations are always caught up. The only reliable
 * post-migration signal that no account exists on this device is a
 * zero-row `users` table, which is exactly what routes the device to
 * `mobile.welcome` — leading into `/signup` ("Create account") or
 * `/mobile/pair` ("Import from another device").
 *
 * Without this gate a fresh 0-user device hits `auth` middleware on any
 * protected route, throws `AuthenticationException`, and lands on the
 * desktop-shaped `/login` (Sign in) screen instead of the mobile
 * first-run welcome — exactly the bug this middleware closes.
 *
 * Both first-launch routes themselves — plus the signup ceremony they lead
 * into and the pairing/setup surfaces beyond it — are exempted so the
 * redirect cannot loop. The exempt list is name-prefix-matched, mirroring
 * the desktop gate's contract.
 *
 * The Livewire AJAX update endpoint is also exempted for the same reason
 * the desktop gate exempts it: the signup form's `submit` call funnels
 * through `*livewire.update`, which must not be short-circuited to
 * `/mobile/welcome` before `SignupAction` runs.
 */
final class MobileEnsureDatabaseReady
{
    /**
     * Route-name prefixes this middleware never redirects away from.
     *
     * `mobile.welcome` is the gated-state-rendering surface itself.
     * `signup` is the first-user ceremony the welcome screen's "Create
     * account" CTA leads into (Phase 12 D-03 / `FirstUserOnlyMiddleware`
     * gates it to `User::count() === 0` independently). `mobile.import`
     * (Phase 15 import-join) is the "Import from another device" CTA's
     * NEW fresh-device local-identity bootstrap target — it also runs
     * before any user exists, so it must be exempt for the same reason
     * `mobile.welcome`/`signup` are. `mobile.pair` is the pairing entry
     * point `mobile.import` redirects into once its own bootstrap has
     * created a local user (by then the device is no longer "fresh", so
     * this exemption is mostly relevant for the pre-Phase-15-import-join
     * "scan without importing an identity" desktop-mirrored flow).
     * `mobile.setup` is the post-pairing resumable initial-sync gate.
     * `setup` covers the shared Onboarding module's post-signup
     * first-run wizard (mirrors the desktop gate's own `setup`
     * exemption).
     *
     * `site.webmanifest` and `pwa.icon` cover the web manifest and PWA
     * icon set — public artifacts required before any user exists,
     * mirroring the desktop gate's exemption for the same routes.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTE_PREFIXES = [
        'mobile.welcome',
        'signup',
        'mobile.import',
        'mobile.pair',
        'mobile.setup',
        'setup',
        'site.webmanifest',
        'pwa.icon',
    ];

    /**
     * Route-name suffixes this middleware never redirects away from. See
     * the class docblock — the Livewire AJAX update endpoint must reach
     * the signup form's `submit()` handler on a fresh install.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTE_SUFFIXES = [
        'livewire.update',
    ];

    public function __construct(
        private readonly MobileFirstLaunchBootstrap $bootstrap,
        private readonly UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if ($this->bootstrap->isFreshInstall()) {
            return new RedirectResponse($this->urls->route('mobile.welcome'));
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
