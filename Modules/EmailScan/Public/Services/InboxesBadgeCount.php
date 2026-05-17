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

        $candidates = self::toInt(
            $this->db->connection()
                ->table('discovered_senders')
                ->where('user_id', $user->id)
                ->where('state', 'candidate')
                ->where('occurrence_count', '>=', DiscoveredSenderQuery::MIN_OCCURRENCES)
                ->where('last_seen_at', '>=', $threshold)
                ->count(),
        );

        $reauth = self::toInt(
            $this->db->connection()
                ->table('inbox_scan_state')
                ->where('user_id', $user->id)
                ->where('status', 'needs_reauth')
                ->count(),
        );

        return $candidates + $reauth;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
