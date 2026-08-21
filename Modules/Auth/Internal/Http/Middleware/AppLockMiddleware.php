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
        // Driven entirely by wire:poll, which does not count as activity, so
        // the PIN dropped over a working screen. No financial data here.
        'mobile.pair',
        'mobile.setup',
        'mobile.setup.done',
    ];

    /**
     * @link ../../../../../.docs/features/auth/architecture.md#why-the-mobile-runtime-forced-two-changes-here
     */
    private const LIVEWIRE_UPDATE_ROUTE = '*livewire.update';

    // Only the config is cached; last_activity_at is written per request, so
    // a longer TTL would buy nothing and delay a settings change.
    private const SESSION_CONFIG_TTL_SECONDS = 60;

    // Public so an unlock can invalidate it: a stale copy re-locked the
    // session on the next request and asked for the PIN twice.
    public const SESSION_CONFIG_CACHE = 'beatrax_lock_config_cache';

    // Restorable across a lock engaged from the client, which never reaches
    // this middleware.
    public const SESSION_LAST_PAGE = 'beatrax_lock_last_page';

    public const SESSION_BACKGROUNDED_AT = 'beatrax_lock_backgrounded_at';

    // Mirrors lock.js's GRACE_MS, and is the only clock that works on Android:
    // a suspended WebView never fires the page timer.
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

        // Nothing left to verify against, so redirecting would strand a
        // session that was locked before the lock was turned off.
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

        // Ahead of the idle check, and evaluated on every pass, because the
        // marker is spent either way: this request itself proves the app is
        // back in the foreground.
        if ($this->backgroundGraceExpired($session) || $this->isIdleExpired($config)) {
            return $this->lockForIdle($request, $next, $session, $routeName);
        }

        $this->recordActivity($session, $userId, $routeName);
        $this->rememberPage($request, $session, $routeName);

        return $this->pass($request, $next);
    }

    // Runs before anything reads the idle clock: a user who just proved
    // presence must not then be told the session went idle.
    private function settleUnlockActivity(Session $session, int $userId): void
    {
        if ($session->pull(LockStateManager::SESSION_UNLOCK_ACTIVITY_PENDING, false) !== true) {
            return;
        }

        // Cache and row both: LockEngageController's grace window reads the
        // row, and the cache still holds the timestamp the lock rendered with.
        $session->forget(self::SESSION_CONFIG_CACHE);

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $this->clock->now()->toDateTimeString()]);
    }

    // A client-side idle lock goes straight to the lock screen, so the
    // middleware never sees the request that would set `url.intended` --
    // and every idle unlock landed on the dashboard.
    private function rememberPage(Request $request, Session $session, ?string $routeName): void
    {
        if ($this->isExemptRoute($routeName) || ! $this->isRestorablePage($request)) {
            return;
        }

        $session->put(self::SESSION_LAST_PAGE, $request->fullUrl());
    }

    // Restoring a POST would re-submit it, and the Livewire endpoint is not
    // a page anyone can be returned to.
    private function isRestorablePage(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $this->isLivewireUpdate()
            && ! $request->expectsJson();
    }

    // The router's route, not the request: a Livewire update re-runs this
    // middleware against a synthesised request that looks like a plain GET.
    private function isLivewireUpdate(): bool
    {
        return $this->routes->currentRouteNamed(self::LIVEWIRE_UPDATE_ROUTE);
    }

    // Pulled, not read: any request at all means the app is in the foreground
    // again, so the marker is spent on either branch.
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

    // LockScreen has always pulled `url.intended`; this is the only writer of
    // it, so before this the unlock landed on the dashboard.
    private function redirectToLock(Request $request, Session $session): Response
    {
        if ($this->isRestorablePage($request)) {
            $session->put('url.intended', $request->fullUrl());
        }

        $lockUrl = $this->urls->route($this->lockRouteName());

        if ($this->isLivewireUpdate()) {
            // Thrown, not returned: Livewire's pipeline discards a non-redirect
            // response and runs the action anyway -- the thing the lock exists
            // to prevent.
            throw new HttpResponseException($this->livewireLockResponse($lockUrl));
        }

        return new RedirectResponse($lockUrl);
    }

    // `components: []` is what makes this inert: Livewire iterates it to find
    // each message's payload, so empty morphs and throws nothing. The redirect
    // rides in the body, the one part no transport in this stack rewrites.
    /**
     * @see resources/js/lock.js — the interceptor that acts on this.
     * @link ../../../../../.docs/features/auth/architecture.md#why-the-mobile-runtime-forced-two-changes-here
     */
    private function livewireLockResponse(string $lockUrl): JsonResponse
    {
        return new JsonResponse([
            'components' => [],
            'beatraxLock' => ['redirect' => $lockUrl],
        ]);
    }

    // `auth.lock` is the desktop card; without this a locked phone rendered it
    // as a small centred panel instead of a full-screen lock.
    private function lockRouteName(): string
    {
        return UserDataPathService::isMobileRuntime() && $this->routes->has('mobile.lock')
            ? 'mobile.lock'
            : 'auth.lock';
    }

    // Livewire updates are not activity: wire:poll traffic would hold the idle
    // timer open forever.
    private function recordActivity(Session $session, int $userId, ?string $routeName): void
    {
        if ($this->isExemptRoute($routeName) || $this->isLivewireUpdate()) {
            return;
        }

        $now = $this->clock->now();
        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $now->toDateTimeString()]);

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
