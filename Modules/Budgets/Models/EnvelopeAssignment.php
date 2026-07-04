<?php

declare(strict_types=1);

namespace Modules\Budgets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Category;

/**
 * A per-(user, category, period) assigned amount for zero-based envelope
 * budgeting (D-01).
 *
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property Carbon $period_start
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
            'period_start' => 'date',
        ];
    }
}
