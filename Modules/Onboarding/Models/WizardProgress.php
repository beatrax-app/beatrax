<?php

declare(strict_types=1);

namespace Modules\Onboarding\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $step_key
 * @property string $status
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WizardProgress extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'wizard_progress';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'step_key',
        'status',
        'data',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
