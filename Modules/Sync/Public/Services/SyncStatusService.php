<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Read-only sync-status surface for the D-06 Livewire component.
 *
 * Provides per-peer session status rows from sync_sessions, user-scoped,
 * and derives an overall sync health summary string from those rows.
 *
 * All queries are scoped to the caller's user_id (T-13-16 — no cross-user
 * status leak). The Livewire component reads exclusively via this service
 * and never queries sync_sessions directly (cross-module boundary rule).
 *
 * Singleton registration: SyncServiceProvider already registers this class
 * with a singletonIfExists() guard — no provider edit needed.
 */
final readonly class SyncStatusService
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * Per-peer sync session rows for $userId, ordered by last_seen_at desc.
     *
     * Returns only the most recent session per peer (the latest status row).
     * Rows are stdClass objects with fields:
     *   id, user_id, local_device_id, peer_device_id, status, error_message,
     *   last_seen_at, connected_at, created_at, updated_at.
     *
     * @return array<int, \stdClass>
     */
    public function peerStatuses(int $userId): array
    {
        /** @var array<int, \stdClass> $rows */
        $rows = $this->db->connection()
            ->table('sync_sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * Derive an overall sync health string from $userId's session rows.
     *
     * The derivation rule (in priority order):
     *   'error'      — any row has status='failed' with a non-empty error_message
     *   'syncing'    — any row has status in ('connecting','handshaking','active')
     *   'offline'    — rows exist but none are active/connecting/handshaking
     *   'all_synced' — rows exist and none match error/syncing/offline conditions
     *                  (every peer's last session is 'closed' or unknown but not failed)
     *   'unknown'    — no rows (no peers have ever synced with this user)
     *
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

            // IN-04: track this in the single pass instead of re-looping below.
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
            // All peers are closed/failed-without-message — last sync happened but peers are now offline.
            // A status='closed' row (or failed-with-last-seen) means sync completed
            // but the peer is now disconnected. Computed in the single pass above (IN-04).
            if ($hasClosedOrSynced) {
                return 'all_synced';
            }

            return 'offline';
        }

        return 'all_synced';
    }

    /**
     * Human-readable relative time for the most-recent last_seen_at across all
     * of the user's peer sessions. Returns null when no sessions exist or no
     * last_seen_at has been recorded.
     *
     * The caller (Livewire component) uses this to render "synced Nm ago".
     * The component passes the Clock-derived $now to this method to avoid any
     * use of the global now() helper (larastanStrictRules.noGlobalLaravelFunction).
     *
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

        // diffInSeconds returns negative when $past is in the past relative to $now.
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
