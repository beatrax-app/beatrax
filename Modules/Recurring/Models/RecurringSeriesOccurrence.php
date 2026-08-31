<?php

declare(strict_types=1);

namespace Modules\Recurring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Casts\DateOnlyCast;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Transaction;

// Re-running the detector sweep is idempotent thanks to the
// UNIQUE(recurring_series_id, transaction_id) schema constraint.

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $recurring_series_id
 * @property int $transaction_id
 * @property CarbonImmutable $observed_at
 * @property int $observed_amount_minor
 * @property string $observed_currency
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RecurringSeriesOccurrence extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'recurring_series_id',
        'transaction_id',
        'observed_at',
        'observed_amount_minor',
        'observed_currency',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'observed_at' => DateOnlyCast::class,
            'observed_amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RecurringSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(RecurringSeries::class, 'recurring_series_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
