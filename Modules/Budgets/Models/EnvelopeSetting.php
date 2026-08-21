<?php

declare(strict_types=1);

namespace Modules\Budgets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Category;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $overspend_mode
 * @property int|null $threshold_percent
 */
final class EnvelopeSetting extends Model
{
    use BelongsToUser;

    protected $fillable = [
        'user_id',
        'category_id',
        'overspend_mode',
        'threshold_percent',
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
            'threshold_percent' => 'integer',
        ];
    }
}
