<?php

declare(strict_types=1);

namespace Modules\Community\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the community_merchant_mappings table — the
 * bundled corpus of crowd-sourced merchant identifications consulted
 * by the import preview and the `/community/mystery-merchants` page.
 *
 * Rows with `user_id IS NULL` are global corpus entries seeded from
 * the bundled YAML file at install time; rows with a non-null
 * `user_id` are per-user overrides where the user supplied their own
 * friendly name for a pattern. The BelongsToUser trait is
 * intentionally NOT applied — global rows must remain readable
 * regardless of the authenticated user, and per-user override reads
 * filter explicitly at the call site (mirrors `SystemAlert`).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pattern
 * @property string|null $generalized_pattern
 * @property string $name
 * @property string|null $category
 * @property string|null $region
 * @property string $contributor
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class CommunityMerchantMapping extends Model
{
    /** @var string|null */
    protected $table = 'community_merchant_mappings';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'pattern',
        'generalized_pattern',
        'name',
        'category',
        'region',
        'contributor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
