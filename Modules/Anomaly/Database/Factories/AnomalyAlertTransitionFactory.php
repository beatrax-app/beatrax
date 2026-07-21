<?php

declare(strict_types=1);

namespace Modules\Anomaly\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Anomaly\Models\AnomalyAlertTransition;

// The default state encodes a "user acknowledged an open alert"
// transition. Callers override `from_state` / `to_state` /
// `transition_reason` / `actor` for the snooze / dismiss / undo / revive
// shapes.
/**
 * @extends Factory<AnomalyAlertTransition>
 */
final class AnomalyAlertTransitionFactory extends Factory
{
    /** @var class-string<AnomalyAlertTransition> */
    protected $model = AnomalyAlertTransition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'anomaly_alert_id' => null,
            'from_state' => 'open',
            'to_state' => 'acknowledged',
            'transition_reason' => 'user_action',
            'actor' => 'user',
            'transitioned_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'notes' => null,
        ];
    }
}
