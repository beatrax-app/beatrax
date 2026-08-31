<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PromoteDiscoveredSender
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(int $discoveredSenderId, User $user): void
    {
        // Dispatched after the commit returns, never inside it: a listener
        // firing mid-transaction reads the pre-transaction row, and a later
        // rollback leaves it having acted on a promotion that never happened.
        $mutation = $this->db->connection()->transaction(function () use ($discoveredSenderId, $user): ?EntityMutated {
            $connection = $this->db->connection();

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
                return null;
            }

            $rawSenderEmail = is_string($row->sender_email ?? null) ? $row->sender_email : '';
            $rawSenderName = is_string($row->sender_name ?? null) ? $row->sender_name : null;
            $label = $rawSenderName !== null && $rawSenderName !== ''
                ? $rawSenderName
                : $rawSenderEmail;

            $now = $this->clock->now()->toDateTimeString();

            // Derived, not minted: the row travels, and an autoincrement would
            // name a different sender on the peer.
            $knownSenderId = DerivedRowId::for('known_senders', [
                'user_id' => $user->id,
                'email_pattern' => $rawSenderEmail,
            ]);

            $connection->table('known_senders')->insert([
                'id' => $knownSenderId,
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

            return new EntityMutated(
                table: 'known_senders',
                pk: $knownSenderId,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'email_pattern' => $rawSenderEmail,
                    'label' => $label,
                    'source' => 'user',
                    'added_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        });

        if ($mutation !== null) {
            $this->events->dispatch($mutation);
        }
    }
}
