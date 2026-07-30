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

    // Priority order: an errored peer outranks a syncing one, which outranks
    // a finished one. Written as the ladder the caller reads rather than as
    // nested guards, so the order is the code rather than a comment beside it.
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
        $hasFinished = false;

        foreach ($rows as $row) {
            $vars = get_object_vars($row);
            $status = is_string($vars['status'] ?? null) ? $vars['status'] : '';
            $errorMsg = is_string($vars['error_message'] ?? null) ? $vars['error_message'] : '';
            $lastSeen = is_string($vars['last_seen_at'] ?? null) ? $vars['last_seen_at'] : '';

            $hasError = $hasError || ($status === 'failed' && $errorMsg !== '');
            $hasSyncing = $hasSyncing || in_array($status, ['connecting', 'handshaking', 'active'], true);
            // A closed row, or a failed one that was seen at least once, means
            // the sync finished and the peer has since gone away.
            $hasFinished = $hasFinished || $status === 'closed' || ($status === 'failed' && $lastSeen !== '');
        }

        return match (true) {
            $hasError => 'error',
            $hasSyncing => 'syncing',
            $hasFinished => 'all_synced',
            default => 'offline',
        };
    }

    // Returns null when no session has recorded a last_seen_at. The caller
    // passes the Clock-derived $now so nothing here reaches for the global
    // now() helper.
    /**
     * @param  CarbonImmutable  $now  The reference point for the relative diff.
     */
    public function lastSyncedHuman(CarbonImmutable $now, int $userId): ?string
    {
        $latest = $this->latestLastSeen($userId);

        return $latest === null ? null : self::relativeTime($now, $latest);
    }

    // Shared with SyncStatusSection, which renders the same phrasing for each
    // peer rather than for the newest one. It was a second copy of the ladder
    // below until now, identical down to the pluralisation that did nothing.
    public static function relativeTime(CarbonImmutable $now, string $timestamp): ?string
    {
        try {
            $past = CarbonImmutable::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }

        return self::humanizeGap(abs((int) $now->diffInSeconds($past, false)));
    }

    // Timestamps are compared as strings rather than parsed: they are stored
    // in a fixed-width sortable format, so the largest string is the latest
    // instant and parsing every row to find one maximum would be wasted work.
    private function latestLastSeen(int $userId): ?string
    {
        $latest = null;
        foreach ($this->peerStatuses($userId) as $row) {
            $vars = get_object_vars($row);
            $seen = is_string($vars['last_seen_at'] ?? null) && $vars['last_seen_at'] !== ''
                ? $vars['last_seen_at']
                : null;

            if ($seen !== null && ($latest === null || strcmp($seen, $latest) > 0)) {
                $latest = $seen;
            }
        }

        return $latest;
    }

    // One row per magnitude. Only the day arm pluralises: "1m ago" and
    // "{$minutes}m ago" render identically at one minute, so the ternaries
    // that used to guard the minute and hour arms decided nothing.
    private static function humanizeGap(int $seconds): string
    {
        $days = (int) floor($seconds / 86400);

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => ((int) floor($seconds / 60)).'m ago',
            $seconds < 86400 => ((int) floor($seconds / 3600)).'h ago',
            $days === 1 => '1 day ago',
            default => "{$days} days ago",
        };
    }
}
