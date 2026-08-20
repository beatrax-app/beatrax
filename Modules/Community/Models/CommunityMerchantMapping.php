<?php

declare(strict_types=1);

namespace Modules\Community\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $pattern
 * @property string|null $generalized_pattern
 * @property string $name
 * @property string|null $category
 * @property string|null $region
 * @property string $contributor
 * @property string|null $website
 * @property string|null $cancel_url
 * @property string|null $support_url
 * @property string|null $support_phone
 * @property string|null $support_email
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
        'website',
        'cancel_url',
        'support_url',
        'support_phone',
        'support_email',
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
