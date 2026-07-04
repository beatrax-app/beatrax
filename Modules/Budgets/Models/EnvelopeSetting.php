<?php

declare(strict_types=1);

namespace Modules\Budgets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Category;

/**
 * A per-(user, category) envelope setting — currently just `overspend_mode`
 * (D-03): 'reduce_to_budget' | 'carry_negative'. The toggle's lifetime is
 * the envelope, not any month.
 *
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $overspend_mode
 */
final class EnvelopeSetting extends Model
{
    use BelongsToUser;

    protected $fillable = [
        'user_id',
        'category_id',
        'overspend_mode',
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
