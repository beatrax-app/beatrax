<?php

declare(strict_types=1);

namespace Modules\Anomaly\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Anomaly\Database\Factories\AnomalySuppressionRuleFactory;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the anomaly_suppression_rules table (D-17/D-18) —
 * the user-visible, undoable mute list produced when a user dismisses an
 * anomaly "as expected".
 *
 * A rule keys narrowly on merchant (`counterparty_id`) + amount band
 * (`amount_band_low_minor` .. `amount_band_high_minor`) + `detector` +
 * `direction`, so a genuinely larger/different later charge from the same
 * merchant still fires. Per D-18 nothing is muted invisibly — the
 * settings surface lists every rule and lets the user revoke it.
 *
 * Cross-user posture (borrowed from Counterparty): the `BelongsToUser`
 * global scope is a SECONDARY guard that fires only inside HTTP-bound
 * Eloquent queries. Every evaluation-time matching query under
 * queue/console MUST carry its own explicit `where('user_id', ...)`
 * filter — the explicit filter is the primary guard.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $counterparty_id
 * @property string $detector
 * @property string $direction
 * @property int $amount_band_low_minor
 * @property int $amount_band_high_minor
 * @property string $currency
 * @property int|null $source_anomaly_alert_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AnomalySuppressionRule extends Model
{
    use BelongsToUser;

    /** @use HasFactory<AnomalySuppressionRuleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'counterparty_id',
        'detector',
        'direction',
        'amount_band_low_minor',
        'amount_band_high_minor',
        'currency',
        'source_anomaly_alert_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_band_low_minor' => 'integer',
            'amount_band_high_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return AnomalySuppressionRuleFactory::new();
    }
}
