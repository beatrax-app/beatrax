<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the app-lock gate on every authenticated route.
 *
 * When an authenticated user has a locked session, every request is
 * redirected to the app-lock screen. The lock screen (auth.lock) and the
 * logout route are exempt so the user can enter their PIN or sign out
 * without being trapped in a redirect loop.
 *
 * Idle-timeout enforcement (D-17, server-authoritative):
 *   When the user's session is unlocked and the app-lock is enabled,
 *   this middleware checks whether the idle timeout has elapsed since the
 *   last recorded activity (last_activity_at in user_app_lock_configs).
 *   If elapsed, it locks the session and redirects to /lock — matching the
 *   server-authoritative model of D-17. Otherwise it refreshes the timestamp.
 *
 *   To avoid a DB query on every request, the lock config (lock_enabled,
 *   idle_timeout_minutes) is cached in the session under a private key and
 *   re-read from the DB only once per SESSION_CONFIG_TTL_SECONDS window.
 *   The last_activity_at timestamp is written on every passing gated
 *   NON-Livewire request (see LIVEWIRE_HEADER below — WR-04) so the DB stays
 *   fresh even when the in-session config cache is active.
 *
 * Unauthenticated requests pass through untouched: guests are handled by
 * the Authenticate middleware that is prepended to the auth group; the lock
 * is an extra layer on top of authentication, not a replacement.
 *
 * The middleware is registered via:
 *   - $router->pushMiddlewareToGroup('auth', AppLockMiddleware::class)
 *     so every web+auth route is gated (D-01 server-authoritative lock).
 *   - Livewire::addPersistentMiddleware(AppLockMiddleware::class)
 *     so /livewire/update requests re-run the gate on every Livewire
 *     component update, preventing bypass via the update endpoint (T-05-06).
 *
 * D-02 (lock replaces session expiry): the global session lifetime in
 * config/session.php is already set to 43 200 minutes (30 days), which
 * satisfies the rolling-window requirement for all users unconditionally.
 * No per-request runtime override is needed; the lock_enabled flag controls
 * whether the lock gate fires, not the lifetime.
 */
final readonly class AppLockMiddleware
{
    /**
     * Routes reachable from a LOCKED session. Note: biometric ENROLL is
     * deliberately absent (IN-06) — enrollment requires the session data key,
     * which a locked session never has, so exempting it only widened the
     * locked-session surface. Challenge/verify stay exempt (unlock path).
     *
     * `mobile.lock` (Phase 15 Plan 06, R6/MOBILE-01) is the on-device
     * biometric-primary/PIN-fallback unlock screen at `/mobile/lock`. Without
     * this exemption a locked mobile session would be redirected away from
     * its own unlock screen to `auth.lock` on every request, making
     * `MobileLockScreen` structurally unreachable whenever actually locked —
     * this middleware pre-dates Phase 15 and had no mobile-route awareness.
     * The redirect TARGET below still always points at `auth.lock` (desktop/
     * web); the mobile shell is responsible for its own initial navigation
     * to `/mobile/lock` — this exemption only stops that screen, once
     * reached, from immediately bouncing itself away.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTE_NAMES = [
        'auth.lock',
        'auth.lock.biometric.challenge',
        'auth.lock.biometric.verify',
        'auth.lock.engage',
        'mobile.lock',
        'logout',
    ];

    /**
     * Header present on every Livewire update request (the Livewire JS client
     * always sends it; RequireLivewireHeaders rejects updates without it).
     *
     * WR-04: Livewire update traffic — including wire:poll machine traffic —
     * must NOT count as user activity, or any page with a polling component
     * keeps last_activity_at fresh forever and the server idle lock (D-17)
     * never fires on an unattended machine. Genuine interaction on
     * Livewire-heavy pages is reported by the lock.js activity heartbeat
     * (POST auth.lock.activity), a plain fetch request.
     */
    private const LIVEWIRE_HEADER = 'X-Livewire';

    /**
     * How long (in seconds) to trust the in-session config cache before
     * re-reading lock_enabled + idle_timeout_minutes from the DB.
     *
     * 60 s is a reasonable balance: a user who changes idle_timeout_minutes in
     * settings will see the new value within a minute, and the per-request
     * write of last_activity_at keeps the DB fresh independently.
     */
    private const SESSION_CONFIG_TTL_SECONDS = 60;

    /** Session key for the cached config snapshot. */
    private const SESSION_CONFIG_CACHE = 'beatrax_lock_config_cache';

    public function __construct(
        private CurrentUser $currentUser,
        private LockStateManager $lockState,
        private UrlGenerator $urls,
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            $session = $request->session();
            $routeName = $request->route()?->getName();

            // If already locked, redirect to the lock screen (unless exempt).
            if ($this->lockState->isLocked($session)) {
                if (! in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
                    return new RedirectResponse($this->urls->route('auth.lock'));
                }

                /** @var Response $response */
                $response = $next($request);

                return $response;
            }

            // Session is unlocked — enforce idle timeout when lock is enabled.
            $userId = $this->currentUser->user()->id;
            $config = $this->resolveConfig($session, $userId);

            if ($config !== null && $config['lock_enabled']) {
                $now = $this->clock->now();
                $idleMs = $config['idle_timeout_minutes'] * 60 * 1000;

                // Check whether idle timeout has elapsed since last_activity_at.
                $lastActivity = $config['last_activity_at'];
                if ($lastActivity !== null) {
                    $elapsedMs = $now->diffInMilliseconds($lastActivity, absolute: true);

                    if ($elapsedMs >= $idleMs) {
                        // Idle timeout elapsed — lock the session (D-17 server-authoritative).
                        $this->lockState->lock($session);

                        // Invalidate the config cache so the next request re-reads from DB.
                        $session->forget(self::SESSION_CONFIG_CACHE);

                        if (! in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
                            return new RedirectResponse($this->urls->route('auth.lock'));
                        }

                        /** @var Response $response */
                        $response = $next($request);

                        return $response;
                    }
                }

                // Idle timeout not elapsed — refresh last_activity_at in the DB.
                // Exempt routes (lock screen, logout) don't count as activity,
                // and neither do Livewire update requests (WR-04): wire:poll
                // traffic would otherwise hold the idle timer open forever.
                if (! in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)
                    && ! $request->headers->has(self::LIVEWIRE_HEADER)) {
                    $nowStr = $now->toDateTimeString();
                    $this->db->connection()
                        ->table('user_app_lock_configs')
                        ->where('user_id', $userId)
                        ->update(['last_activity_at' => $nowStr]);

                    // Update the cached last_activity_at so the next request within
                    // the TTL window uses the refreshed timestamp without a DB read.
                    $this->refreshCachedActivity($session, $now);
                }
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * Return the cached lock config for $userId, refreshing from the DB when
     * the TTL has expired or the cache is absent.
     *
     * Returns null when the user has no config row (lock never set up).
     *
     * @return array{lock_enabled: bool, idle_timeout_minutes: int, last_activity_at: CarbonImmutable|null, cached_at: int}|null
     */
    private function resolveConfig(Session $session, int $userId): ?array
    {
        $cached = $session->get(self::SESSION_CONFIG_CACHE);

        // IN-07: use the injected Clock (not raw time()) so the TTL window
        // honours time-travel in tests like every other time read here.
        $now = $this->clock->now()->getTimestamp();

        if (is_array($cached)
            && isset($cached['cached_at'])
            && is_int($cached['cached_at'])
            && ($now - $cached['cached_at']) < self::SESSION_CONFIG_TTL_SECONDS) {
            /** @var array{lock_enabled: bool, idle_timeout_minutes: int, last_activity_at: CarbonImmutable|null, cached_at: int} $cached */
            return $cached;
        }

        // Re-read from DB.
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['lock_enabled', 'idle_timeout_minutes', 'last_activity_at']);

        if ($row === null) {
            return null;
        }

        $lastActivityRaw = $row->last_activity_at;
        $lastActivity = null;
        if (is_string($lastActivityRaw) || is_int($lastActivityRaw)) {
            $lastActivity = CarbonImmutable::parse($lastActivityRaw);
        }

        $payload = [
            'lock_enabled' => (bool) $row->lock_enabled,
            'idle_timeout_minutes' => is_numeric($row->idle_timeout_minutes)
                ? (int) $row->idle_timeout_minutes
                : 5,
            'last_activity_at' => $lastActivity,
            'cached_at' => $now,
        ];

        $session->put(self::SESSION_CONFIG_CACHE, $payload);

        return $payload;
    }

    /**
     * Update the in-session config cache's last_activity_at field.
     *
     * Called after a successful DB write of last_activity_at so subsequent
     * requests within the TTL window see the fresh timestamp without
     * a DB query.
     */
    private function refreshCachedActivity(Session $session, CarbonImmutable $now): void
    {
        $cached = $session->get(self::SESSION_CONFIG_CACHE);
        if (! is_array($cached)) {
            return;
        }

        $cached['last_activity_at'] = $now;
        $session->put(self::SESSION_CONFIG_CACHE, $cached);
    }
}
