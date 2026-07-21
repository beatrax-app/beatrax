<?php

declare(strict_types=1);

namespace Modules\Anomaly\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Anomaly\Database\Factories\AnomalySuppressionRuleFactory;
use Modules\Core\Public\Concerns\BelongsToUser;

// A rule keys narrowly on merchant + amount band + detector + direction,
// so a genuinely larger/different later charge still fires. `BelongsToUser`
// is a SECONDARY guard — every evaluation-time query under queue/console
// must carry its own explicit `where('user_id', ...)` filter.
/**
 * @link ../../../.docs/features/anomaly/architecture.md
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
