<?php

declare(strict_types=1);

namespace Modules\Anomaly\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Anomaly\Database\Factories\AnomalyAlertFactory;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Transaction;

// The `state` column is mutated exclusively by AnomalyAlertStateMachine;
// the schema-level trigger pair plus the noOtherAnomalyAlertStateMutator
// arch-test invariant enforce the sole-mutator contract. `transaction_id`
// is the idempotency seam (UNIQUE) the evaluator re-runs against.
/**
 * @link ../../../.docs/features/anomaly/architecture.md
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $transaction_id
 * @property string $state
 * @property string $direction
 * @property list<string> $reasons
 * @property string|null $dismissed_as
 * @property int|null $baseline_amount_minor
 * @property int|null $latest_amount_minor
 * @property string|null $currency
 * @property int|null $sensitivity_percent_used
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $detected_at
 * @property CarbonImmutable|null $actioned_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Transaction $transaction
 */
final class AnomalyAlert extends Model
{
    use BelongsToUser;

    /** @use HasFactory<AnomalyAlertFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'transaction_id',
        'state',
        'direction',
        'reasons',
        'dismissed_as',
        'baseline_amount_minor',
        'latest_amount_minor',
        'currency',
        'sensitivity_percent_used',
        'snoozed_until',
        'detected_at',
        'actioned_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'baseline_amount_minor' => 'integer',
            'latest_amount_minor' => 'integer',
            'sensitivity_percent_used' => 'integer',
            'snoozed_until' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'actioned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /** @return HasMany<AnomalyAlertTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(AnomalyAlertTransition::class);
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return AnomalyAlertFactory::new();
    }
}
