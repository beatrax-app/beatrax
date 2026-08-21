<?php

declare(strict_types=1);

namespace Modules\Anomaly\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Ledger\Public\Enums\Direction;

// Callers must override `transaction_id`: the FK rejects the `null` default.
/**
 * @extends Factory<AnomalyAlert>
 */
final class AnomalyAlertFactory extends Factory
{
    /** @var class-string<AnomalyAlert> */
    protected $model = AnomalyAlert::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'transaction_id' => null,
            'state' => AnomalyAlertState::Open->value,
            'direction' => Direction::Expense->value,
            'reasons' => ['large'],
            'dismissed_as' => null,
            'baseline_amount_minor' => -999,
            'latest_amount_minor' => -2349,
            'currency' => 'EUR',
            'sensitivity_percent_used' => 50,
            'snoozed_until' => null,
            'detected_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'actioned_at' => null,
        ];
    }

    public function open(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => AnomalyAlertState::Open->value,
            'actioned_at' => null,
            'snoozed_until' => null,
            'dismissed_as' => null,
        ]);
    }

    public function acknowledged(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => AnomalyAlertState::Acknowledged->value,
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }

    public function snoozed(CarbonImmutable $until): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => AnomalyAlertState::Snoozed->value,
            'snoozed_until' => $until,
            'actioned_at' => CarbonImmutable::now(),
        ]);
    }

    public function dismissed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => AnomalyAlertState::Dismissed->value,
            'dismissed_as' => 'not_unusual',
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }
}
