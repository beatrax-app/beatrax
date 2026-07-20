<?php

declare(strict_types=1);

namespace Modules\Forecasting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Forecasting\Database\Factories\ForecastScenarioMutationFactory;
use Modules\Forecasting\Internal\Casts\ScenarioMutationPayloadCast;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;

/**
 * @see ScenarioMutationPayloadCast
 *
 * @property int $id
 * @property int $user_id
 * @property int $forecast_scenario_id
 * @property string $kind
 * @property int|null $target_series_id
 * @property ScenarioMutationPayload $payload
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ForecastScenario $scenario
 */
final class ForecastScenarioMutation extends Model
{
    use BelongsToUser;

    /** @use HasFactory<ForecastScenarioMutationFactory> */
    use HasFactory;

    protected $table = 'forecast_scenario_mutations';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'forecast_scenario_id',
        'kind',
        'target_series_id',
        'payload',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_series_id' => 'integer',
            'payload' => ScenarioMutationPayloadCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ForecastScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ForecastScenario::class, 'forecast_scenario_id');
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return ForecastScenarioMutationFactory::new();
    }
}
