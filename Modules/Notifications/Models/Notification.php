<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property string $id
 * @property int $user_id
 * @property string $state
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $dismissed_at
 * @property string $title
 * @property string $body
 * @property string|null $params
 * @property string $trigger_type
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Notification extends Model
{
    use BelongsToUser;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'state',
        'read_at',
        'dismissed_at',
        'title',
        'body',
        'params',
        'trigger_type',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
