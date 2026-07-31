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
    ];

    // Present on every Livewire update request; the Livewire JS client
    // always sends it, so it identifies wire:poll/component-update traffic
    // that must not count as user activity.
    private const LIVEWIRE_HEADER = 'X-Livewire';

    // 60s balances "settings change takes effect quickly" against DB load:
    // the per-request write of last_activity_at keeps the DB fresh
    // independent of this cache anyway.
    private const SESSION_CONFIG_TTL_SECONDS = 60;

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
            return new RedirectResponse($this->urls->route('auth.lock'));
        }

        return $this->pass($request, $next);
    }

    private function handleUnlocked(Request $request, Closure $next, Session $session, int $userId, ?string $routeName): Response
    {
        $config = $this->resolveConfig($session, $userId);
        if ($config === null || ! $config['lock_enabled']) {
            return $this->pass($request, $next);
        }

        if ($this->isIdleExpired($config)) {
            return $this->lockForIdle($request, $next, $session, $routeName);
        }

        $this->recordActivity($request, $session, $userId, $routeName);

        return $this->pass($request, $next);
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
            return new RedirectResponse($this->urls->route('auth.lock'));
        }

        return $this->pass($request, $next);
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
