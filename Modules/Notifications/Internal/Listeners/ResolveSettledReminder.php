<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Recurring\Public\Events\PaymentSettled;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Req 13's after-delivery withdrawal half: when a previously-reminded
 * charge settles, flips the existing inbox row to `resolved` so the inbox
 * never asserts an unpaid state for a paid charge.
 *
 * Subscribes to `Modules\Recurring\Public\Events\PaymentSettled` —
 * registered by `NotificationsServiceProvider`'s guarded listener table
 * (18-05); this class's exact name/namespace is what flips that guard on.
 *
 * Re-derives the SAME deterministic id `PersistPaymentReminder` wrote from
 * `(userId, TRIGGER_PAYMENT_REMINDER, seriesId, dueDate)` — never inline
 * (D-38 invariant 3), always through `DeterministicKeyDeriver`. If no row
 * exists for that tuple (the reminder never fired — e.g. the before-fire
 * settlement check in `EmitPaymentRemindersJob` already caught it, or the
 * charge simply predates any lead-time window), this is a silent no-op,
 * not an error.
 *
 * Never-throw envelope (D-07) mirrors `PersistPaymentReminder` /
 * `SyncCaptureListener::handle()`: a failed resolve must never break the
 * originating settlement-detection path.
 */
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
                // The reminder never fired for this (series, due date) pair
                // — nothing to withdraw. Silent no-op, not an error.
                return;
            }

            $state = is_string($row->state) ? $row->state : '';
            if ($state !== 'open') {
                // Already resolved (or an unexpected state) — never call
                // the state machine for a transition it would reject.
                return;
            }

            $this->stateMachine->resolve($id, $event->userId);
        } catch (Throwable $e) {
            // Swallow — a failed resolve must NEVER break the originating
            // settlement-detection path (D-07).
            $this->log->error('ResolveSettledReminder: failed to resolve settled reminder', [
                'exception' => $e->getMessage(),
                'seriesId' => $event->seriesId,
                'userId' => $event->userId,
            ]);
        }
    }
}
