<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertSnoozed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Snoozes an open (or already-snoozed) anomaly_alerts row until the
 * supplied timestamp. The state-machine call carries the `snoozed_until`
 * patch in its `$extraColumns` so the state flip and the snooze timestamp
 * land inside the same row-locked transaction and audit row.
 *
 * The snooze target is bounded SERVER-SIDE to `(now, now+6mo]` (T-09-10):
 * a tampered Livewire payload widening the snooze window past six months
 * — or pointing at a past timestamp — is rejected with an
 * InvalidArgumentException before any state change. This bound lives in
 * the action (not the Livewire layer) so every caller is protected.
 *
 * Idempotent when re-snoozing to the exact same target timestamp (silent
 * no-op, compared via Unix timestamps so it stays correct across
 * timezones). Cross-user invocation raises NotFoundHttpException.
 */
final class SnoozeAnomalyAlert
{
    /** Server-side upper bound on a snooze window: six months from now. */
    private const MAX_SNOOZE_MONTHS = 6;

    public function __construct(
        private readonly AnomalyAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $alertId, User $user, CarbonImmutable $until): void
    {
        /** @var AnomalyAlert|null $alert */
        $alert = AnomalyAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Anomaly alert not found.');
        }

        // Server-side bounds (now, now+6mo]. The 404 guard runs first so a
        // cross-user probe never learns whether its tampered target was in
        // range.
        $now = $this->clock->now();
        if ($until->lessThanOrEqualTo($now)) {
            throw new InvalidArgumentException('Snooze target must be in the future.');
        }
        if ($until->greaterThan($now->addMonths(self::MAX_SNOOZE_MONTHS))) {
            throw new InvalidArgumentException('Snooze target may not exceed six months from now.');
        }

        // Compare via Unix timestamps so the idempotency check stays
        // correct across timezones (the stored snoozed_until casts in the
        // app timezone while the caller's $until may carry a distinct
        // offset from its ISO source).
        if (
            $alert->state === 'snoozed'
            && $alert->snoozed_until !== null
            && $alert->snoozed_until->getTimestamp() === $until->getTimestamp()
        ) {
            return;
        }

        $untilString = $until->toDateTimeString();

        $this->stateMachine->transition(
            $alert,
            'snoozed',
            'user_action',
            'user',
            'snoozed_until='.$untilString,
            ['snoozed_until' => $untilString],
        );

        $this->events->dispatch(new AnomalyAlertSnoozed(
            userId: $user->id,
            anomalyAlertId: $alertId,
            snoozedUntil: $until,
        ));
    }
}
