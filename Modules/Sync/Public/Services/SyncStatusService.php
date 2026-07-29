<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class SyncStatusService
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * @return array<int, \stdClass>
     */
    public function peerStatuses(int $userId): array
    {
        return $this->db->connection()
            ->table('sync_sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->get()
            ->all();
    }

    // Priority order: 'error' if any row failed with a message; 'syncing' if
    // any row is connecting/handshaking/active; 'offline'/'all_synced'
    // depending on whether any row is currently active; 'unknown' if no rows.
    /**
     * @return 'all_synced'|'syncing'|'offline'|'error'|'unknown'
     */
    public function overallStatus(int $userId): string
    {
        $rows = $this->peerStatuses($userId);

        if ($rows === []) {
            return 'unknown';
        }

        $hasError = false;
        $hasSyncing = false;
        $hasActive = false;
        $hasClosedOrSynced = false;

        foreach ($rows as $row) {
            $vars = get_object_vars($row);
            $status = is_string($vars['status'] ?? null) ? $vars['status'] : '';
            $errorMsg = is_string($vars['error_message'] ?? null) ? $vars['error_message'] : '';
            $lastSeen = is_string($vars['last_seen_at'] ?? null) ? $vars['last_seen_at'] : '';

            if ($status === 'failed' && $errorMsg !== '') {
                $hasError = true;
            }

            if (in_array($status, ['connecting', 'handshaking', 'active'], true)) {
                $hasSyncing = true;
            }

            if ($status === 'active') {
                $hasActive = true;
            }

            if ($status === 'closed' || ($status === 'failed' && $lastSeen !== '')) {
                $hasClosedOrSynced = true;
            }
        }

        if ($hasError) {
            return 'error';
        }

        if ($hasSyncing) {
            return 'syncing';
        }

        if (! $hasActive) {
            // A status='closed' row (or failed-with-last-seen) means sync
            // completed but the peer is now disconnected.
            if ($hasClosedOrSynced) {
                return 'all_synced';
            }

            return 'offline';
        }

        return 'all_synced';
    }

    // Returns null when no sessions exist or no last_seen_at has been
    // recorded. The caller passes the Clock-derived $now to avoid any use
    // of the global now() helper.
    /**
     * @param  CarbonImmutable  $now  The reference point for the relative diff.
     */
    public function lastSyncedHuman(CarbonImmutable $now, int $userId): ?string
    {
        $rows = $this->peerStatuses($userId);

        $latestTs = null;
        foreach ($rows as $row) {
            $vars = get_object_vars($row);
            $ts = is_string($vars['last_seen_at'] ?? null) && $vars['last_seen_at'] !== '' ? $vars['last_seen_at'] : null;
            if ($ts === null) {
                continue;
            }

            if ($latestTs === null || strcmp($ts, $latestTs) > 0) {
                $latestTs = $ts;
            }
        }

        if ($latestTs === null) {
            return null;
        }

        try {
            $past = CarbonImmutable::parse($latestTs);
        } catch (\Throwable) {
            return null;
        }

        $diffSeconds = (int) $now->diffInSeconds($past, false);
        $absDiff = abs($diffSeconds);

        if ($absDiff < 60) {
            return 'just now';
        }

        if ($absDiff < 3600) {
            $minutes = (int) floor($absDiff / 60);

            return $minutes === 1 ? '1m ago' : "{$minutes}m ago";
        }

        if ($absDiff < 86400) {
            $hours = (int) floor($absDiff / 3600);

            return $hours === 1 ? '1h ago' : "{$hours}h ago";
        }

        $days = (int) floor($absDiff / 86400);

        return $days === 1 ? '1 day ago' : "{$days} days ago";
    }
}
