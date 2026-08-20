<?php

declare(strict_types=1);

namespace Modules\Counterparties\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property string $slug
 * @property string $display_name
 * @property string|null $iban
 * @property string|null $merchant_name
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Counterparty extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'counterparties';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'slug',
        'display_name',
        'iban',
        'merchant_name',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
