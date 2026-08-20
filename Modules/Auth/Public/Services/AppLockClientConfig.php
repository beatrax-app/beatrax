<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

// Sole source of truth for "does this session have a lock at all",
// read by the authenticated layout (to emit window.beatraxIdleMs) and
// by LockEngageController (to refuse a lock it could never unlock), so
// the client and server halves of that gate cannot drift apart.
final class AppLockClientConfig
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    // No config row, or lock_enabled off, means no PIN hash and no
    // enrolled biometric — nothing that could release a locked session.
    public function isEnabled(int $userId): bool
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['lock_enabled']);

        return $row !== null && (bool) $row->lock_enabled;
    }

    // When this user last proved presence. Null when no lock row exists,
    // which callers read as "no recent activity to protect".
    public function lastActivityAt(int $userId): ?CarbonImmutable
    {
        $value = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->value('last_activity_at');

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

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
