<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Top-nav "Inboxes" badge feed.
 *
 * Sums two counts the user needs to act on: (a) discovered-sender
 * candidates awaiting review (above the panel's promotion threshold —
 * 2 occurrences within 90 days) and (b) inboxes that need re-auth
 * because the OAuth refresh-token chain broke.
 *
 * Plan 09 amends the candidates count to apply the same 2/90 threshold
 * the panel uses (DiscoveredSenderQuery::MIN_OCCURRENCES = 2,
 * WITHIN_DAYS = 90). Without that, the badge would surface single-shot
 * senders that never appear in the panel — clicking the link would
 * land on an "no discoveries" surface and the badge would never
 * decrement. Matching the threshold keeps badge and panel in lockstep.
 */
final class InboxesBadgeCount
{
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
                    'needs_reauth',
                ],
            );

        if (! is_object($row) || ! property_exists($row, 'total')) {
            return 0;
        }

        return self::toInt($row->total);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
