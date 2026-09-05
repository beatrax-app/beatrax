<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;

// When this session last showed presence, and whether that was long enough
// ago to lock. It is one subject rather than several: every writer here feeds
// the same reading, and a stamp written in one place and read against a
// timeout resolved in another is how a re-lock arrives one request too early.
/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final readonly class LockIdleClock
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // Runs before anything reads the idle clock: a user who just proved
    // presence must not then be told the session went idle.
    public function settleUnlock(Session $session, int $userId): void
    {
        if ($session->pull(LockStateManager::SESSION_UNLOCK_ACTIVITY_PENDING, false) !== true) {
            return;
        }

        // Cache and row both: LockEngageController's grace window reads the
        // row, and the cache still holds the timestamp the lock rendered with.
        $session->forget(AppLockMiddleware::SESSION_CONFIG_CACHE);

        $this->stamp($session, $userId, $this->clock->now());
    }

    // Pulled, not read: any request at all means the app is in the foreground
    // again, so the marker is spent on either branch.
    public function backgroundGraceExpired(Session $session): bool
    {
        $markedAt = $session->pull(AppLockMiddleware::SESSION_BACKGROUNDED_AT);

        if (! is_int($markedAt)) {
            return false;
        }

        return $this->clock->now()->getTimestamp() - $markedAt >= IdleTimeoutOptions::BACKGROUND_GRACE_SECONDS;
    }

    // The row's stamp is the seed, not the answer: it carries a session that
    // predates this key, and every other session's activity along with it.
    /**
     * @param  array{lock_enabled: bool, idle_timeout_minutes: int, last_activity_at: CarbonImmutable|null, cached_at: int}  $config
     */
    public function idleExpired(Session $session, array $config): bool
    {
        $lastActivity = $this->sessionActivity($session) ?? $config['last_activity_at'];
        if ($lastActivity === null) {
            return false;
        }

        $idleMs = $config['idle_timeout_minutes'] * Duration::Minute->milliseconds();
        $elapsedMs = $this->clock->now()->diffInMilliseconds($lastActivity, absolute: true);

        return $elapsedMs >= $idleMs;
    }

    public function recordActivity(Session $session, int $userId): void
    {
        $now = $this->clock->now();
        $this->stamp($session, $userId, $now);

        $this->refreshCachedActivity($session, $now);
    }

    private function stamp(Session $session, int $userId, CarbonImmutable $now): void
    {
        $session->put(AppLockMiddleware::SESSION_LAST_ACTIVITY, $now->getTimestamp());

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $now->toDateTimeString()]);
    }

    private function sessionActivity(Session $session): ?CarbonImmutable
    {
        $stamp = $session->get(AppLockMiddleware::SESSION_LAST_ACTIVITY);

        return is_int($stamp) ? CarbonImmutable::createFromTimestamp($stamp) : null;
    }

    private function refreshCachedActivity(Session $session, CarbonImmutable $now): void
    {
        $cached = $session->get(AppLockMiddleware::SESSION_CONFIG_CACHE);
        if (! is_array($cached)) {
            return;
        }

        $cached['last_activity_at'] = $now;
        $session->put(AppLockMiddleware::SESSION_CONFIG_CACHE, $cached);
    }
}
