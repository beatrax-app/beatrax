<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Illuminate\Database\DatabaseManager;

// Consumed by the authenticated layout to decide whether to emit
// window.beatraxIdleMs, the value lock.js uses to arm the idle watcher.
// Null (lock disabled / no config row) tells the layout to omit it, so
// lock.js no-ops the idle tracker for users without a lock.
final class AppLockClientConfig
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

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
