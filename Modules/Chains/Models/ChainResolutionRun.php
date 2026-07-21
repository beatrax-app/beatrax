<?php

declare(strict_types=1);

namespace Modules\Chains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @link ../../../.docs/features/chains/architecture.md
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $job_uuid
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string $status
 * @property int $linked_count
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ChainResolutionRun extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'job_uuid',
        'started_at',
        'completed_at',
        'status',
        'linked_count',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'linked_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
