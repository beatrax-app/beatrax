<?php

declare(strict_types=1);

namespace Modules\Goals\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Goals\Database\Factories\GoalFactory;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property int $target_minor
 * @property string $target_currency
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $target_date
 * @property string $status
 */
final class Goal extends Model
{
    use BelongsToUser;

    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'target_minor',
        'target_currency',
        'start_date',
        'target_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_minor' => 'integer',
            'start_date' => 'immutable_date',
            'target_date' => 'immutable_date',
        ];
    }

    // Laravel's default resolver derives Database\Factories\<ModelName>Factory,
    // which is not where module factories live — point directly at GoalFactory.
    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return GoalFactory::new();
    }
}
