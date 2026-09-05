<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $kind
 * @property string|null $dedup_key
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
        'dedup_key',
        'kind',
        'severity',
        'message',
        'metadata',
        'acknowledged_at',
    ];

    // created_at only, because there is no updated_at column. Left to the
    // schema's CURRENT_TIMESTAMP default it was SQLite's clock, which is
    // always UTC: on a phone in CEST an alert raised at 01:38 was stored and
    // shown as 23:38 the previous day, while every other row read local.
    /**
     * @var bool
     */
    public $timestamps = true;

    public const UPDATED_AT = null;

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
