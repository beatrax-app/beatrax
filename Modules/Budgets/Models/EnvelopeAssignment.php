<?php

declare(strict_types=1);

namespace Modules\Budgets\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Category;

/**
 * A per-(user, category, period) assigned amount for zero-based envelope
 * budgeting (D-01).
 *
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property CarbonImmutable $period_start
 * @property int $assigned_minor
 * @property string $currency
 */
final class EnvelopeAssignment extends Model
{
    use BelongsToUser;

    protected $fillable = [
        'user_id',
        'category_id',
        'period_start',
        'assigned_minor',
        'currency',
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_minor' => 'integer',
        ];
    }

    /**
     * WR-05: `period_start` is a plain month-key date. A `'date'` cast would
     * serialize on save() with the grammar's full datetime format
     * ("Y-m-d 00:00:00"), reintroducing the Plan 03 pitfall that breaks the
     * fold's exact `where('period_start', 'Y-m-d')` string match. This
     * Attribute stores a bare `Y-m-d` string (matching what EnvelopeWriter's
     * raw query-builder writes) while still reading back a CarbonImmutable, so
     * the model and the writer agree on storage format and the trap is
     * impossible rather than merely avoided.
     *
     * @return Attribute<CarbonImmutable|null, string>
     */
    protected function periodStart(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?CarbonImmutable => is_string($value) && $value !== ''
                ? CarbonImmutable::parse($value)
                : null,
            set: static fn (mixed $value): string => $value instanceof \DateTimeInterface
                ? CarbonImmutable::parse($value)->toDateString()
                : CarbonImmutable::parse(is_scalar($value) ? (string) $value : '')->toDateString(),
        );
    }
}
