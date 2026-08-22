<?php

declare(strict_types=1);

namespace Modules\Anomaly\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Anomaly\Models\AnomalySuppressionRule;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;

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
            'currency' => Currency::Eur->value,
            'source_anomaly_alert_id' => null,
        ];
    }
}
