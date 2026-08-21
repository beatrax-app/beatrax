<?php

declare(strict_types=1);

namespace Modules\Forecasting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Forecasting\Database\Factories\ForecastScenarioFactory;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ForecastScenario extends Model
{
    public const int MAX_NAME_LENGTH = 120;

    use BelongsToUser;

    /** @use HasFactory<ForecastScenarioFactory> */
    use HasFactory;

    protected $table = 'forecast_scenarios';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ForecastScenarioMutation, $this> */
    public function mutations(): HasMany
    {
        return $this->hasMany(ForecastScenarioMutation::class, 'forecast_scenario_id');
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return ForecastScenarioFactory::new();
    }
}
