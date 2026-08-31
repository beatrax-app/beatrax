<?php

declare(strict_types=1);

namespace Modules\Recurring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Public\Casts\DateOnlyCast;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $direction
 * @property string $detected_name
 * @property string|null $display_name_override
 * @property string $state
 * @property string $cadence
 * @property int $latest_amount_minor
 * @property string $latest_currency
 * @property int|null $monthly_equivalent_minor
 * @property int $variance_tolerance_percent
 * @property int|null $latest_funding_chain_link_id
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $next_expected_at
 * @property bool $next_expected_confidence_low
 * @property int|null $billing_day
 * @property string $cluster_key
 * @property string|null $cluster_counterparty_key
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ChainLink|null $latestFundingChainLink
 */
final class RecurringSeries extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'direction',
        'detected_name',
        'display_name_override',
        'state',
        'cadence',
        'latest_amount_minor',
        'latest_currency',
        'monthly_equivalent_minor',
        'variance_tolerance_percent',
        'latest_funding_chain_link_id',
        'snoozed_until',
        'next_expected_at',
        'next_expected_confidence_low',
        'billing_day',
        'cluster_key',
        'cluster_counterparty_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latest_amount_minor' => 'integer',
            'monthly_equivalent_minor' => 'integer',
            'variance_tolerance_percent' => 'integer',
            'snoozed_until' => 'immutable_datetime',
            'next_expected_at' => DateOnlyCast::class,
            'next_expected_confidence_low' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Only fires for direct Eloquent inserts, typically fixtures: every
        // detector path sets cluster_counterparty_key explicitly.
        self::saving(static function (self $series): void {
            if ($series->cluster_counterparty_key === null) {
                $series->cluster_counterparty_key = $series->detected_name;
            }
        });
    }

    /** @return BelongsTo<ChainLink, $this> */
    public function latestFundingChainLink(): BelongsTo
    {
        return $this->belongsTo(ChainLink::class, 'latest_funding_chain_link_id');
    }

    /** @return HasMany<RecurringSeriesTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(RecurringSeriesTransition::class);
    }

    /** @return HasMany<RecurringSeriesOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringSeriesOccurrence::class);
    }
}
