<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            if ($rawState !== DiscoveredSenderState::Candidate->value) {
                return;
            }

            $connection->table('discovered_senders')
                ->where('id', $discoveredSenderId)
                ->where('user_id', $user->id)
                ->update([
                    'state' => DiscoveredSenderState::Dismissed->value,
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });
    }
}
