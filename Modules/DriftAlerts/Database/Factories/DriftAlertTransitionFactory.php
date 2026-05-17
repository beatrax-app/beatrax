<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DriftAlerts\Models\DriftAlertTransition;

/**
 * Eloquent factory for the drift_alert_transitions audit table.
 *
 * Default state mirrors the canonical "user acknowledged an open
 * alert" transition; later waves wire factory states for the
 * snooze / dismiss / revive lifecycle when their state-machine
 * methods land.
 *
 * The `protected $model` reference is a string FQN; the
 * `DriftAlertTransition` Eloquent model lands in a later wave (the
 * table migration ships then) so production wiring resolves the
 * class at factory-invoke time, not at factory-class-load time.
 *
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
            'from_state' => 'open',
            'to_state' => 'acknowledged',
            'transition_reason' => 'user_action',
            'actor' => 'user',
            'transitioned_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'notes' => null,
        ];
    }
}
