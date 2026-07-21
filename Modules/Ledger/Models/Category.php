<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string $kind
 * @property int $display_order
 */
final class Category extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = ['user_id', 'parent_id', 'name', 'slug', 'kind', 'display_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
