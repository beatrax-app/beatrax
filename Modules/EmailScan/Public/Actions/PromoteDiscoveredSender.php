<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action: promote a discovered_senders candidate into the
 * user's known_senders allow-list and transition the discovered row
 * to state='added'.
 *
 * Cross-user 404 invariant: the lookup is scoped to (id, user_id);
 * a row that belongs to another user raises NotFoundHttpException so
 * a forged senderId in the wire payload cannot leak the existence of
 * another user's discovered row.
 *
 * Idempotency: a row already in state='added' or 'dismissed' is a
 * silent no-op. Re-promoting an already-promoted sender does NOT
 * insert a duplicate known_senders row and does not raise.
 *
 * Transaction shape: the insert + the state transition wrap in a
 * single DB transaction with PRAGMA busy_timeout=5000 + lockForUpdate
 * on the discovered_senders row. SQLite's lockForUpdate is a syntactic
 * no-op (single writer), but the busy_timeout is the load-bearing
 * fence that serialises concurrent Promote / Dismiss calls against
 * the same row.
 */
final class PromoteDiscoveredSender
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
                // Idempotent — already promoted or dismissed; do nothing.
                return;
            }

            $rawSenderEmail = is_string($row->sender_email ?? null) ? $row->sender_email : '';
            $rawSenderName = is_string($row->sender_name ?? null) ? $row->sender_name : null;
            $label = $rawSenderName !== null && $rawSenderName !== ''
                ? $rawSenderName
                : $rawSenderEmail;

            $now = $this->clock->now()->toDateTimeString();

            $connection->table('known_senders')->insert([
                'user_id' => $user->id,
                'email_pattern' => $rawSenderEmail,
                'label' => $label,
                'source' => 'user',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $connection->table('discovered_senders')
                ->where('id', $discoveredSenderId)
                ->where('user_id', $user->id)
                ->update([
                    'state' => 'added',
                    'updated_at' => $now,
                ]);
        });
    }
}
