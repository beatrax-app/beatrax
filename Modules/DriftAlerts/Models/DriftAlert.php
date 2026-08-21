<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\DriftAlerts\Database\Factories\DriftAlertFactory;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $recurring_series_id
 * @property string $state
 * @property string $direction
 * @property int $baseline_amount_minor
 * @property int $latest_amount_minor
 * @property string $currency
 * @property int $delta_minor
 * @property int $annualized_impact_minor
 * @property int $threshold_percent_used
 * @property string $threshold_source
 * @property int $latest_occurrence_id
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $actioned_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read RecurringSeries $recurringSeries
 * @property-read RecurringSeriesOccurrence $latestOccurrence
 */
final class DriftAlert extends Model
{
    use BelongsToUser;

    /** @use HasFactory<DriftAlertFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'recurring_series_id',
        'state',
        'direction',
        'baseline_amount_minor',
        'latest_amount_minor',
        'currency',
        'delta_minor',
        'annualized_impact_minor',
        'threshold_percent_used',
        'threshold_source',
        'latest_occurrence_id',
        'snoozed_until',
        'detected_at',
        'actioned_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'baseline_amount_minor' => 'integer',
            'latest_amount_minor' => 'integer',
            'delta_minor' => 'integer',
            'annualized_impact_minor' => 'integer',
            'threshold_percent_used' => 'integer',
            'snoozed_until' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'actioned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RecurringSeries, $this> */
    public function recurringSeries(): BelongsTo
    {
        return $this->belongsTo(RecurringSeries::class, 'recurring_series_id');
    }

    /** @return BelongsTo<RecurringSeriesOccurrence, $this> */
    public function latestOccurrence(): BelongsTo
    {
        return $this->belongsTo(RecurringSeriesOccurrence::class, 'latest_occurrence_id');
    }

    /** @return HasMany<DriftAlertTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(DriftAlertTransition::class);
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return DriftAlertFactory::new();
    }
}
