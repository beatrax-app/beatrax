<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DriftAlerts\Models\DriftAlert;

// The default state mirrors a 15% monthly expense drift (EUR 9.99 to
// 11.49). Callers must override the three FK columns (user_id,
// recurring_series_id, latest_occurrence_id) — the schema constraints
// reject the factory defaults of null.

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
            'state' => 'open',
            'direction' => 'expense',
            'baseline_amount_minor' => -999,
            'latest_amount_minor' => -1149,
            'currency' => 'EUR',
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

    // Default state, but kept symmetric with the three terminal states
    // below so call sites read explicitly.
    public function open(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'open',
            'actioned_at' => null,
            'snoozed_until' => null,
        ]);
    }

    // The user reviewed it and dismissed the alert without recording any
    // further intent.
    public function acknowledged(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'acknowledged',
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }

    // Caller supplies snoozed_until explicitly — the revival sweep flips
    // the row back to open once the timestamp is in the past.
    public function snoozed(CarbonImmutable $until): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'snoozed',
            'snoozed_until' => $until,
            'actioned_at' => CarbonImmutable::now(),
        ]);
    }

    // Records the user's intent that the underlying recurring series was
    // cancelled outside the app.
    public function dismissedCancelled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'dismissed_cancelled',
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }
}
