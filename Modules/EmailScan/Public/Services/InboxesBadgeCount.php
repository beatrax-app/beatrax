<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

// Top-nav "Inboxes" badge feed: sums discovered-sender candidates
// awaiting review (matching DiscoveredSenderQuery's MIN_OCCURRENCES/
// WITHIN_DAYS threshold, so the badge never surfaces a single-shot
// sender absent from the panel) plus inboxes needing re-auth.
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

        // One round-trip via subqueries summed in SQL rather than two
        // COUNT queries summed in PHP. The view composer fires on
        // every top-nav render (12 queries/min at the dashboard's 5s
        // poll → 6 queries/min after this).
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
