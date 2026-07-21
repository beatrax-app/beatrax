<?php

declare(strict_types=1);

namespace Modules\Community\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @link ../../../.docs/features/community/architecture.md
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
