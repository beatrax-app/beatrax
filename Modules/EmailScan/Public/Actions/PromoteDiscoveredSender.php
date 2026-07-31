<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Promotes a discovered_senders candidate into known_senders and
// transitions the row to state='added'. Cross-user 404 via the (id,
// user_id) scoped lookup; idempotent (already added/dismissed is a
// silent no-op) via one busy_timeout-fenced transaction.
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
            if ($rawState !== DiscoveredSenderState::Candidate->value) {
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
                    'state' => DiscoveredSenderState::Added->value,
                    'updated_at' => $now,
                ]);
        });
    }
}
