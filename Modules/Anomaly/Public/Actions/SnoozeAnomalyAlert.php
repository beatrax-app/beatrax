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

// The snooze target is bounded SERVER-SIDE to (now, now+6mo]: a tampered
// Livewire payload widening the window or pointing at the past is
// rejected before any state change. This bound lives in the action (not
// the Livewire layer) so every caller is protected.
final class SnoozeAnomalyAlert
{
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

        // The 404 guard runs first so a cross-user probe never learns
        // whether its tampered target was in range.
        $now = $this->clock->now();
        if ($until->lessThanOrEqualTo($now)) {
            throw new InvalidArgumentException('Snooze target must be in the future.');
        }
        if ($until->greaterThan($now->addMonths(self::MAX_SNOOZE_MONTHS))) {
            throw new InvalidArgumentException('Snooze target may not exceed six months from now.');
        }

        $untilString = $until->toDateTimeString();

        // Compare through the same toDateTimeString() round-trip the
        // stored value uses (it drops sub-second precision and the source
        // offset), so a raw getTimestamp() comparison against the
        // caller's $until could spuriously differ on a genuine re-snooze.
        if (
            $alert->state === 'snoozed'
            && $alert->snoozed_until !== null
            && $alert->snoozed_until->toDateTimeString() === $untilString
        ) {
            return;
        }

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
