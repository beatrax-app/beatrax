<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Anomaly\Public\Events\AnomalyAlertSnoozed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SnoozeUntil;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The bound lives in the action rather than the Livewire layer, so a tampered
// payload is rejected for every caller.
final readonly class SnoozeAnomalyAlert
{
    public function __construct(
        private AnomalyAlertStateMachine $stateMachine,
        private Dispatcher $events,
        private Clock $clock,
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
        $bounded = SnoozeUntil::from($until, $this->clock->now());
        $untilString = $bounded->toDateTimeString();

        // Compare through the same toDateTimeString() round-trip the stored
        // value took: it drops sub-second precision and the source offset, so
        // a raw timestamp comparison would miss an identical re-snooze.
        if (
            $alert->state === AnomalyAlertState::Snoozed->value
            && $alert->snoozed_until !== null
            && $alert->snoozed_until->toDateTimeString() === $untilString
        ) {
            return;
        }

        // A second tab, or the paired device, acting on a row this one still
        // shows as open is a no-op, not a 500: acknowledged is terminal and
        // dismissed leads only back to open.
        if (! AnomalyAlertState::from($alert->state)->allows(AnomalyAlertState::Snoozed)) {
            return;
        }

        $this->stateMachine->transition(
            $alert,
            AnomalyAlertState::Snoozed->value,
            'user_action',
            'user',
            'snoozed_until='.$untilString,
            ['snoozed_until' => $untilString],
        );

        $this->events->dispatch(new AnomalyAlertSnoozed(
            userId: $user->id,
            anomalyAlertId: $alertId,
            snoozedUntil: $bounded->at,
        ));
    }
}
