<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DriftAlerts\Models\DriftAlert;

/**
 * Eloquent factory for the drift_alerts table.
 *
 * The default state mirrors a 15% monthly expense drift (€9.99 →
 * €11.49, +€1.80 delta, +€21.60 annualized at the ×12 monthly
 * multiplier). State factories cover the four lifecycle phases:
 * open() (the default), acknowledged(), snoozed($until), and
 * dismissedCancelled().
 *
 * Callers must override the three FK columns (`user_id`,
 * `recurring_series_id`, `latest_occurrence_id`) — the schema
 * constraints reject the factory defaults of `null`.
 *
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

    /**
     * State factory: an open alert. This is the default state but the
     * helper is kept symmetric with the three terminal states below
     * so call sites read explicitly.
     */
    public function open(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'open',
            'actioned_at' => null,
            'snoozed_until' => null,
        ]);
    }

    /**
     * State factory: an acknowledged alert. The user reviewed it and
     * dismissed the alert without recording any further intent. Sets
     * `actioned_at` to a point inside the default detection window.
     */
    public function acknowledged(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'acknowledged',
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }

    /**
     * State factory: a snoozed alert. Caller supplies the
     * `snoozed_until` timestamp explicitly — the revival sweep flips
     * the row back to `open` once the timestamp is in the past.
     */
    public function snoozed(CarbonImmutable $until): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'snoozed',
            'snoozed_until' => $until,
            'actioned_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * State factory: a dismissed-as-cancelled alert. Records the
     * user's intent that the underlying recurring series was
     * cancelled outside the app; downstream phases consume the
     * `DriftAlertDismissedCancelled` event emitted by the
     * corresponding Public Action.
     */
    public function dismissedCancelled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'dismissed_cancelled',
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }
}
