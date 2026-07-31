<?php

declare(strict_types=1);

namespace Modules\Anomaly\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Anomaly\Models\AnomalySuppressionRule;
use Modules\Ledger\Public\Enums\Direction;

// The default state encodes a large-vs-typical expense suppression rule
// banding ±20% around a €23.49 charge (€18.79 .. €28.19) for a single
// merchant. Callers override `user_id` + `counterparty_id` to scope the
// rule, and may override `detector` / `direction` / the band columns.
/**
 * @extends Factory<AnomalySuppressionRule>
 */
final class AnomalySuppressionRuleFactory extends Factory
{
    /** @var class-string<AnomalySuppressionRule> */
    protected $model = AnomalySuppressionRule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'counterparty_id' => null,
            'detector' => 'large',
            'direction' => Direction::Expense->value,
            'amount_band_low_minor' => -2819,
            'amount_band_high_minor' => -1879,
            'currency' => 'EUR',
            'source_anomaly_alert_id' => null,
        ];
    }
}
