<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\DriftAlerts\Database\Factories\DriftAlertTransitionFactory;

// Append-only: DriftAlertStateMachine is the only writer.

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $drift_alert_id
 * @property string $from_state
 * @property string $to_state
 * @property string $transition_reason
 * @property string $actor
 * @property CarbonImmutable $transitioned_at
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DriftAlertTransition extends Model
{
    use BelongsToUser;

    /** @use HasFactory<DriftAlertTransitionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'drift_alert_id',
        'from_state',
        'to_state',
        'transition_reason',
        'actor',
        'transitioned_at',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transitioned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DriftAlert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(DriftAlert::class, 'drift_alert_id');
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return DriftAlertTransitionFactory::new();
    }
}
