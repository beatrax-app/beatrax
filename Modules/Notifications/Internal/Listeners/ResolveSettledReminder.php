<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Enums\NotificationState;
use Modules\Recurring\Public\Events\PaymentSettled;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

final class ResolveSettledReminder
{
    public function __construct(
        private readonly DeterministicKeyDeriver $keys,
        private readonly DatabaseManager $db,
        private readonly NotificationStateMachine $stateMachine,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(PaymentSettled $event): void
    {
        try {
            $id = $this->keys->derive(
                $event->userId,
                DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER,
                (string) $event->seriesId,
                $event->dueDate->toDateString(),
            );

            $row = $this->db->connection()->table('notifications')
                ->where('id', $id)
                ->where('user_id', $event->userId)
                ->first(['state']);

            if (! $row instanceof stdClass) {
                // The reminder never fired for this (series, due date)
                // pair - nothing to withdraw. Silent no-op, not an error.
                return;
            }

            $state = is_string($row->state) ? $row->state : '';
            if ($state !== NotificationState::Open->value) {
                // Already resolved (or an unexpected state) - never call
                // the state machine for a transition it would reject.
                return;
            }

            $this->stateMachine->resolve($id, $event->userId);
        } catch (Throwable $e) {
            // Swallow - a failed resolve must never break the
            // originating settlement-detection path.
            $this->log->error('ResolveSettledReminder: failed to resolve settled reminder', [
                ...SafeExceptionContext::describe($e),
                'seriesId' => $event->seriesId,
                'userId' => $event->userId,
            ]);
        }
    }
}
