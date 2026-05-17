<?php

declare(strict_types=1);

namespace Modules\Recurring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the recurring_series_transitions table — the
 * append-only audit trail produced by `RecurringSeriesStateMachine`.
 *
 * One row per state transition. `actor` is either `user` (review-
 * surface promotion / rejection / snooze) or `detector` (cadence flip
 * after a new occurrence lands). `transition_reason` is the structured
 * code (e.g. `user_action`, `detector_cadence_flip`, `detector_promoted`,
 * `snooze_expired`) that downstream surfaces narrate to the user.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $recurring_series_id
 * @property string $from_state
 * @property string $to_state
 * @property string $transition_reason
 * @property string $actor
 * @property CarbonImmutable $transitioned_at
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RecurringSeriesTransition extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'recurring_series_id',
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

    /** @return BelongsTo<RecurringSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(RecurringSeries::class, 'recurring_series_id');
    }
}
