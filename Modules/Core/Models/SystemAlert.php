<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @link ../../../.docs/features/core/architecture.md
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $kind
 * @property string $severity
 * @property string $message
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $acknowledged_at
 */
final class SystemAlert extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'kind',
        'severity',
        'message',
        'metadata',
        'acknowledged_at',
    ];

    /**
     * @var bool
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'acknowledged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
