<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

// The candidate half reuses DiscoveredSenderQuery's MIN_OCCURRENCES /
// WITHIN_DAYS threshold, so the badge can never count a sender the panel
// itself will not show.
final class InboxesBadgeCount
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function forCurrentUser(User $user): int
    {
        $threshold = $this->clock
            ->now()
            ->modify('-'.DiscoveredSenderQuery::WITHIN_DAYS.' days')
            ->toDateTimeString();

        // Summed in SQL, not as two COUNTs: the composer fires on every
        // top-nav render, so the dashboard's 5s poll costs 6 queries/min
        // instead of 12.
        $row = $this->db->connection()
            ->selectOne(
                'SELECT
                    (
                        SELECT COUNT(*) FROM discovered_senders
                        WHERE user_id = ?
                          AND state = ?
                          AND occurrence_count >= ?
                          AND last_seen_at >= ?
                    )
                    +
                    (
                        SELECT COUNT(*) FROM inbox_scan_state
                        WHERE user_id = ?
                          AND status = ?
                    ) AS total',
                [
                    $user->id,
                    'candidate',
                    DiscoveredSenderQuery::MIN_OCCURRENCES,
                    $threshold,
                    $user->id,
                    InboxScanStatus::NeedsReauth->value,
                ],
            );

        if (! is_object($row) || ! property_exists($row, 'total')) {
            return 0;
        }

        return self::toInt($row->total);
    }
}
