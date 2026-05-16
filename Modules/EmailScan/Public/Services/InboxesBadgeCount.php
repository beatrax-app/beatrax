<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Top-nav "Inboxes" badge feed.
 *
 * Sums two counts the user needs to act on: (a) discovered-senders
 * candidates awaiting review and (b) inboxes that need re-auth
 * because the OAuth refresh-token chain broke. The Phase 7 plan
 * wires this into the top-nav View Factory composer; Phase 6's
 * Plan 03 only ships the query so the composer wiring is a
 * one-line change in the later plan.
 */
final class InboxesBadgeCount
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function forCurrentUser(User $user): int
    {
        $candidates = self::toInt(
            $this->db->connection()
                ->table('discovered_senders')
                ->where('user_id', $user->id)
                ->where('state', 'candidate')
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
