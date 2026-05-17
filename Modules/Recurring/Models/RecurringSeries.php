<?php

declare(strict_types=1);

namespace Modules\Recurring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the recurring_series table — the unified detector
 * output covering both expense and income recurring patterns.
 *
 * The `direction` enum (`expense` | `income`) lets a single detector
 * code path serve both sides. The `state` column is mutated exclusively
 * by `RecurringSeriesStateMachine`; the schema-level trigger pair plus
 * the BoundaryArchTest invariant `noOtherRecurringSeriesStateMutator`
 * enforce that contract.
 *
 * `latest_funding_chain_link_id` is the optional pointer back into the
 * funding-chain ledger so the review surface can render "funded by
 * PayPal · backed by ASN" hints alongside the series row. The link is
 * nullable on delete: a removed chain_link nulls the pointer here
 * rather than removing the series.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $direction
 * @property string $detected_name
 * @property string|null $display_name_override
 * @property string $state
 * @property string $cadence
 * @property int $latest_amount_minor
 * @property string $latest_currency
 * @property string|null $latest_fx_rate_used
 * @property int|null $monthly_equivalent_minor
 * @property int $variance_tolerance_percent
 * @property int|null $latest_funding_chain_link_id
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $next_expected_at
 * @property bool $next_expected_confidence_low
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
        'latest_fx_rate_used',
        'monthly_equivalent_minor',
        'variance_tolerance_percent',
        'latest_funding_chain_link_id',
        'snoozed_until',
        'next_expected_at',
        'next_expected_confidence_low',
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
            'next_expected_at' => 'immutable_date',
            'next_expected_confidence_low' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
