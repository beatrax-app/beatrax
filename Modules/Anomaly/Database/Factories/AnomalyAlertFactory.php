<?php

declare(strict_types=1);

namespace Modules\Anomaly\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Anomaly\Models\AnomalyAlert;

// The default state encodes a large-vs-typical expense anomaly: a €23.49
// charge against a €9.99 per-merchant baseline, flagged `large`. Callers
// must override the `transaction_id` FK — the schema constraint rejects
// the factory default of `null`.
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
            'state' => 'open',
            'direction' => 'expense',
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

    // Default state, kept symmetric with the terminal states below so
    // call sites read explicitly.
    public function open(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'open',
            'actioned_at' => null,
            'snoozed_until' => null,
            'dismissed_as' => null,
        ]);
    }

    // The user reviewed the alert and confirmed "seen it, it's fine"
    // (History tab) without recording any suppression intent.
    public function acknowledged(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'acknowledged',
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }

    // Caller supplies `snoozed_until` explicitly — the revival sweep
    // flips the row back to `open` once the timestamp is in the past.
    public function snoozed(CarbonImmutable $until): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'snoozed',
            'snoozed_until' => $until,
            'actioned_at' => CarbonImmutable::now(),
        ]);
    }

    // Dismissed-as-expected (Dismissed tab): records the user's intent
    // that the charge is not unusual and creates a suppression rule. The
    // dismissal is undoable via the `dismissed -> open` state edge.
    public function dismissed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'dismissed',
            'dismissed_as' => 'not_unusual',
            'actioned_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ]);
    }
}
