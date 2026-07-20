<?php

declare(strict_types=1);

namespace Modules\Forecasting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Forecasting\Database\Factories\ForecastRunFactory;
use Modules\Forecasting\Internal\StateMachines\ForecastRunStateMachine;

/**
 * @see ForecastRunStateMachine
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $scenario_id
 * @property int $horizon_days
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ForecastScenario|null $scenario
 */
final class ForecastRun extends Model
{
    use BelongsToUser;

    /** @use HasFactory<ForecastRunFactory> */
    use HasFactory;

    protected $table = 'forecast_runs';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'scenario_id',
        'horizon_days',
        'started_at',
        'completed_at',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'horizon_days' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ForecastScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ForecastScenario::class, 'scenario_id');
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return ForecastRunFactory::new();
    }
}
