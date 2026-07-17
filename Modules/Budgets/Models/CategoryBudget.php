<?php

declare(strict_types=1);

namespace Modules\Budgets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Category;

/**
 * A monthly spending budget for one (user, category).
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $category_id
 * @property string $period_type
 * @property int $budget_minor
 * @property string $currency
 */
final class CategoryBudget extends Model
{
    use BelongsToUser;

    protected $fillable = [
        'user_id',
        'category_id',
        'period_type',
        'budget_minor',
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
            'budget_minor' => 'integer',
        ];
    }
}
