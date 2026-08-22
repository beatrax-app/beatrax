<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;

// Callers must override recurring_series_id and latest_occurrence_id: both are
// non-nullable FKs the factory defaults to null.

/**
 * @extends Factory<DriftAlert>
 */
final class DriftAlertFactory extends Factory
{
    /** @var class-string<DriftAlert> */
    protected $model = DriftAlert::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'recurring_series_id' => null,
            'state' => DriftAlertState::Open->value,
            'direction' => Direction::Expense->value,
            'baseline_amount_minor' => -999,
            'latest_amount_minor' => -1149,
            'currency' => Currency::Eur->value,
            'delta_minor' => -150,
            'annualized_impact_minor' => -1800,
            'threshold_percent_used' => 5,
            'threshold_source' => 'global',
            'latest_occurrence_id' => null,
            'snoozed_until' => null,
            'detected_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'actioned_at' => null,
        ];
    }

    public function open(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => DriftAlertState::Open->value,
            'actioned_at' => null,
            'snoozed_until' => null,
        ]);
    }

    public function acknowledged(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => DriftAlertState::Acknowledged->value,
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }

    public function snoozed(CarbonImmutable $until): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => DriftAlertState::Snoozed->value,
            'snoozed_until' => $until,
            'actioned_at' => CarbonImmutable::now(),
        ]);
    }

    public function dismissedCancelled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => DriftAlertState::DismissedCancelled->value,
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }
}
