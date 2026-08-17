<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Symfony\Component\HttpFoundation\Response;

/**
 * @link ../../../../../.docs/features/auth/architecture.md
 */
final readonly class AppLockMiddleware
{
    /**
     * @var list<string>
     */
    private const ALLOWED_ROUTE_NAMES = [
        'auth.lock',
        'auth.lock.biometric.challenge',
        'auth.lock.biometric.verify',
        'auth.lock.engage',
        'mobile.lock',
        'logout',
        // The onboarding ceremony is driven entirely by wire:poll, and a
        // Livewire request deliberately does not count as activity below — so
        // the idle timer expired while the user sat watching a working screen
        // and the PIN dropped over it. These screens show no financial data.
        'mobile.pair',
        'mobile.setup',
        'mobile.setup.done',
    ];

    // Present on every Livewire update request; the Livewire JS client
    // always sends it, so it identifies wire:poll/component-update traffic
    // that must not count as user activity.
    private const LIVEWIRE_HEADER = 'X-Livewire';

    // 60s balances "settings change takes effect quickly" against DB load:
    // the per-request write of last_activity_at keeps the DB fresh
    // independent of this cache anyway.
    private const SESSION_CONFIG_TTL_SECONDS = 60;

    // Public so a successful unlock can invalidate it. The cache holds
    // last_activity_at, and a stale copy re-locked the session on the very
    // next request — making the user enter their PIN twice.
    public const SESSION_CONFIG_CACHE = 'beatrax_lock_config_cache';

    // The last page this user was actually on, kept so an unlock can restore
    // it even when the lock was engaged from the client.
    public const SESSION_LAST_PAGE = 'beatrax_lock_last_page';

    // When the client last reported leaving the foreground, as a unix
    // timestamp. Written by lock.js the moment it happens, so the elapsed
    // time can be judged here rather than by a timer in the page.
    public const SESSION_BACKGROUNDED_AT = 'beatrax_lock_backgrounded_at';

    // Mirrors lock.js's GRACE_MS. Its timer is the fast path where it runs at
    // all: an Android WebView is suspended while backgrounded, so the timeout
    // never fires there and only this clock can measure the absence.
    private const BACKGROUND_GRACE_SECONDS = 30;

    public function __construct(
        private CurrentUser $currentUser,
        private LockStateManager $lockState,
        private UrlGenerator $urls,
        private DatabaseManager $db,
        private Clock $clock,
        private Router $routes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->currentUser->isAuthenticated()) {
            return $this->pass($request, $next);
        }

        $session = $request->session();
        $routeName = $request->route()?->getName();
        $userId = $this->currentUser->user()->id;

        if ($this->lockState->isLocked($session)) {
            return $this->handleLocked($request, $next, $session, $userId, $routeName);
        }

        return $this->handleUnlocked($request, $next, $session, $userId, $routeName);
    }

    private function handleLocked(Request $request, Closure $next, Session $session, int $userId, ?string $routeName): Response
    {
        $lockedConfig = $this->resolveConfig($session, $userId);

        // A locked session whose user has no enabled lock can never be
        // opened -- no PIN hash to verify against, no enrolled biometric.
        // Release it rather than redirect, so a session locked before the
        // lock was turned off is not stranded.
        if ($lockedConfig === null || ! $lockedConfig['lock_enabled']) {
            $this->lockState->clearStaleLock($session);

            return $this->pass($request, $next);
        }

        if (! $this->isExemptRoute($routeName)) {
            return $this->redirectToLock($request, $session);
        }

        return $this->pass($request, $next);
    }

    private function handleUnlocked(Request $request, Closure $next, Session $session, int $userId, ?string $routeName): Response
    {
        $this->settleUnlockActivity($session, $userId);

        $config = $this->resolveConfig($session, $userId);
        if ($config === null || ! $config['lock_enabled']) {
            return $this->pass($request, $next);
        }

        // Before the idle check: a request proves the app is in the
        // foreground, so the marker is spent either way — it either locks now
        // or is cleared as a return within grace.
        if ($this->backgroundGraceExpired($session)) {
            return $this->lockForIdle($request, $next, $session, $routeName);
        }

        if ($this->isIdleExpired($config)) {
            return $this->lockForIdle($request, $next, $session, $routeName);
        }

        $this->recordActivity($request, $session, $userId, $routeName);
        $this->rememberPage($request, $session, $routeName);

        return $this->pass($request, $next);
    }

    // Turns a just-completed unlock into recorded activity, before anything
    // reads the idle clock. Proving presence and then being told the session
    // has been idle too long is the same contradiction whichever way it
    // arrives — a re-lock here, or a racing engage POST at the controller.
    private function settleUnlockActivity(Session $session, int $userId): void
    {
        if ($session->pull(LockStateManager::SESSION_UNLOCK_ACTIVITY_PENDING, false) !== true) {
            return;
        }

        // Both halves matter. The row is what LockEngageController's grace
        // window consults, and the cached copy still carries the stale
        // timestamp the lock screen was rendered with.
        $session->forget(self::SESSION_CONFIG_CACHE);

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $this->clock->now()->toDateTimeString()]);
    }

    // The page to come back to after an unlock. The idle timer locks from
    // JAVASCRIPT and goes straight to the lock screen, so the middleware never
    // sees the request that would have set `url.intended` — and every idle
    // unlock dropped the user on the dashboard.
    private function rememberPage(Request $request, Session $session, ?string $routeName): void
    {
        if ($this->isExemptRoute($routeName) || ! $this->isRestorablePage($request)) {
            return;
        }

        $session->put(self::SESSION_LAST_PAGE, $request->fullUrl());
    }

    // Only a plain GET page is worth restoring. Replaying a POST after an
    // unlock would re-submit it, and the Livewire endpoint is not a page a
    // user can be returned to at all.
    private function isRestorablePage(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->headers->has(self::LIVEWIRE_HEADER)
            && ! $request->expectsJson();
    }

    // Whether the app was backgrounded longer ago than the grace window. The
    // marker is PULLED, not read: any request at all means the app is in the
    // foreground again, so it has served its purpose on either branch.
    private function backgroundGraceExpired(Session $session): bool
    {
        $markedAt = $session->pull(self::SESSION_BACKGROUNDED_AT);

        if (! is_int($markedAt)) {
            return false;
        }

        return $this->clock->now()->getTimestamp() - $markedAt >= self::BACKGROUND_GRACE_SECONDS;
    }

    /**
     * @param  array{lock_enabled: bool, idle_timeout_minutes: int, last_activity_at: CarbonImmutable|null, cached_at: int}  $config
     */
    private function isIdleExpired(array $config): bool
    {
        $lastActivity = $config['last_activity_at'];
        if ($lastActivity === null) {
            return false;
        }

        $idleMs = $config['idle_timeout_minutes'] * 60 * 1000;
        $elapsedMs = $this->clock->now()->diffInMilliseconds($lastActivity, absolute: true);

        return $elapsedMs >= $idleMs;
    }

    private function lockForIdle(Request $request, Closure $next, Session $session, ?string $routeName): Response
    {
        $this->lockState->lock($session);
        $session->forget(self::SESSION_CONFIG_CACHE);

        if (! $this->isExemptRoute($routeName)) {
            return $this->redirectToLock($request, $session);
        }

        return $this->pass($request, $next);
    }

    // Sends the user to the lock screen, remembering where they were so
    // unlocking returns them there instead of the dashboard. LockScreen
    // already pulls `url.intended`; nothing ever put anything in it.
    private function redirectToLock(Request $request, Session $session): Response
    {
        if ($this->isRestorablePage($request)) {
            $session->put('url.intended', $request->fullUrl());
        }

        $lockUrl = $this->urls->route($this->lockRouteName());

        if ($request->headers->has(self::LIVEWIRE_HEADER)) {
            // Thrown, not returned. Livewire re-runs this middleware for an
            // update request through a pipeline of its own, and that pipeline
            // stops for a RedirectResponse and NOTHING else — every other
            // response it simply discards before hydrating the component and
            // running the action. Returning the lock answer would therefore
            // have served it while also doing the thing the lock exists to
            // prevent. Throwing is the same mechanism Livewire's own
            // `abort($response)` uses one line further on.
            throw new HttpResponseException($this->livewireLockResponse($lockUrl));
        }

        return new RedirectResponse($lockUrl);
    }

    /**
     * The lock answer for a Livewire XHR, which cannot be a redirect.
     *
     * Livewire's client reads the response body as JSON. Handed a 302 to an
     * HTML page it has two escape routes, and on Android neither is available:
     * `response.redirected` is false because NativePHP's bridge follows the
     * redirect in-process and hands back an ordinary response, and a non-2xx
     * status would only reach the branch that renders the body in a modal. So
     * the lock page's HTML went into `JSON.parse`, threw
     * `SyntaxError: Unexpected token '<'`, and left the old component and the
     * new page half-mounted over each other: the lock screen painted as a
     * narrow inset column over the page it was meant to replace, then blanked,
     * and no further tap produced a request at all. Only a force-stop
     * recovered it — a locked app that cannot be unlocked or logged out of.
     *
     * `components: []` is what makes this inert rather than merely different.
     * Livewire iterates that array to find the payload for each message it
     * sent; empty means it finds nothing, morphs nothing and throws nothing,
     * so a client that somehow never registered the interceptor below is left
     * exactly where it was instead of wrecked. The redirect travels in the
     * BODY, not a header or a status, because the body is the one part of the
     * answer no transport in this stack rewrites — the same reason the resume
     * check reads its answer there.
     *
     * @see resources/js/lock.js — the interceptor that acts on this.
     */
    private function livewireLockResponse(string $lockUrl): JsonResponse
    {
        return new JsonResponse([
            'components' => [],
            'beatraxLock' => ['redirect' => $lockUrl],
        ]);
    }

    // The mobile runtime has its own full-screen lock screen with safe-area
    // chrome; `auth.lock` is the desktop one, which renders as a narrow
    // centred card. Nothing routed here before, so a locked phone always got
    // the desktop screen — a small panel with margins instead of a lock.
    private function lockRouteName(): string
    {
        return UserDataPathService::isMobileRuntime() && $this->routes->has('mobile.lock')
            ? 'mobile.lock'
            : 'auth.lock';
    }

    // Exempt routes (lock screen, logout) don't count as activity, and
    // neither do Livewire update requests: wire:poll traffic would otherwise
    // hold the idle timer open forever.
    private function recordActivity(Request $request, Session $session, int $userId, ?string $routeName): void
    {
        if ($this->isExemptRoute($routeName) || $request->headers->has(self::LIVEWIRE_HEADER)) {
            return;
        }

        $now = $this->clock->now();
        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $now->toDateTimeString()]);

        // Update the cached last_activity_at so the next request within
        // the TTL window uses the refreshed timestamp without a DB read.
        $this->refreshCachedActivity($session, $now);
    }

    private function isExemptRoute(?string $routeName): bool
    {
        return in_array($routeName, self::ALLOWED_ROUTE_NAMES, true);
    }

    private function pass(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * @return array{lock_enabled: bool, idle_timeout_minutes: int, last_activity_at: CarbonImmutable|null, cached_at: int}|null
     */
    private function resolveConfig(Session $session, int $userId): ?array
    {
        $cached = $session->get(self::SESSION_CONFIG_CACHE);

        // Uses the injected Clock (not raw time()) so the TTL window honours
        // time-travel in tests like every other time read here.
        $now = $this->clock->now()->getTimestamp();

        if (is_array($cached)
            && isset($cached['cached_at'])
            && is_int($cached['cached_at'])
            && ($now - $cached['cached_at']) < self::SESSION_CONFIG_TTL_SECONDS) {
            /** @var array{lock_enabled: bool, idle_timeout_minutes: int, last_activity_at: CarbonImmutable|null, cached_at: int} $cached */
            return $cached;
        }

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
