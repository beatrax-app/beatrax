<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action: dismiss a discovered_senders candidate so the daily
 * DiscoveryScanJob excludes it on every subsequent run AND the
 * /inboxes panel hides it from this user's view.
 *
 * Mirrors PromoteDiscoveredSender's invariants:
 *  - Cross-user 404 via the (id, user_id) scoped lookup.
 *  - Idempotent — a row already in state='dismissed' or 'added' is a
 *    silent no-op (consistent with Promote's idempotent posture).
 *  - PRAGMA busy_timeout fence inside the wrapping transaction
 *    serialises concurrent Dismiss / Promote calls.
 */
final class DismissDiscoveredSender
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $discoveredSenderId, User $user): void
    {
        $this->db->connection()->transaction(function () use ($discoveredSenderId, $user): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('discovered_senders')
                ->where('id', $discoveredSenderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new NotFoundHttpException('Discovered sender not found.');
            }

            $rawState = is_string($row->state ?? null) ? $row->state : '';
            if ($rawState !== 'candidate') {
                return;
            }

            $connection->table('discovered_senders')
                ->where('id', $discoveredSenderId)
                ->where('user_id', $user->id)
                ->update([
                    'state' => 'dismissed',
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });
    }
}
