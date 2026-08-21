<?php

declare(strict_types=1);

namespace Modules\Import\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

// Resolved at render time and never written back to
// transactions.description. Every read and write carries an explicit
// where('user_id', …), since BelongsToUser's global scope is HTTP-only.
/**
 * @property int $id
 * @property int|null $user_id
 * @property string $pattern
 * @property string $generalized_pattern
 * @property string $friendly_name
 * @property array<int, array<string, mixed>>|null $merged_from
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MerchantAlias extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'merchant_aliases';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'pattern',
        'generalized_pattern',
        'friendly_name',
        'merged_from',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'merged_from' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
