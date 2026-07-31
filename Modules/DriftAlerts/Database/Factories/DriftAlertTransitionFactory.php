<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;

// The default state encodes a "user acknowledged an open alert"
// transition. Callers can override from_state/to_state/transition_reason/
// actor for the snooze/dismiss/revive shapes.

/**
 * @extends Factory<DriftAlertTransition>
 */
final class DriftAlertTransitionFactory extends Factory
{
    /** @var class-string<DriftAlertTransition> */
    protected $model = DriftAlertTransition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'drift_alert_id' => null,
            'from_state' => DriftAlertState::Open->value,
            'to_state' => DriftAlertState::Acknowledged->value,
            'transition_reason' => 'user_action',
            'actor' => 'user',
            'transitioned_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'notes' => null,
        ];
    }
}
