<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Public\Support\CategoryDisplayName;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string $kind
 * @property int $display_order
 * @property bool $name_is_default
 * @property-read string $display_name
 */
final class Category extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = ['user_id', 'parent_id', 'name', 'slug', 'kind', 'display_order', 'name_is_default'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'name_is_default' => 'boolean',
        ];
    }

    // The Eloquent half of the read seam; every query-builder read site calls
    // CategoryDisplayName directly.
    /** @return Attribute<string, never> */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => CategoryDisplayName::resolve(
            $this->name,
            $this->slug,
            $this->name_is_default,
        ));
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
