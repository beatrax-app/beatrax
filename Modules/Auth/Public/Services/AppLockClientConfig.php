<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Illuminate\Database\DatabaseManager;

/**
 * Public read-only view of the app-lock client configuration.
 *
 * Consumed by the authenticated layout (resources/views/layouts/app.blade.php)
 * to decide whether to emit `window.beatraxIdleMs` — the value lock.js uses
 * to arm the client-side idle watcher (D-17).
 *
 * Contract:
 *   - Lock disabled (or no config row): idleTimeoutMs() returns null and the
 *     layout must NOT emit the variable — lock.js then no-ops the idle
 *     tracker, so users without a lock never get the veil or an idle lock.
 *   - Lock enabled: returns the user's configured idle_timeout_minutes
 *     (1/5/15/30 presets) converted to milliseconds.
 *
 * Lives in Public/ because the layout is outside the Auth module and the
 * module-boundary rule forbids it from querying user_app_lock_configs (or
 * touching Auth internals) directly.
 */
final class AppLockClientConfig
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The idle timeout in milliseconds for a lock-enabled user, or null when
     * the app lock is not enabled for this user.
     */
    public function idleTimeoutMs(int $userId): ?int
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['lock_enabled', 'idle_timeout_minutes']);

        if ($row === null || ! (bool) $row->lock_enabled) {
            return null;
        }

        $minutes = is_numeric($row->idle_timeout_minutes)
            ? (int) $row->idle_timeout_minutes
            : 5;

        return $minutes * 60_000;
    }
}
