<?php

declare(strict_types=1);

namespace Modules\Goals\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Goals\Database\Factories\GoalFactory;
use Modules\Ledger\Models\Account;

/**
 * @link ../../../.docs/features/goals/architecture.md
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $account_id
 * @property string $name
 * @property int $target_minor
 * @property string $target_currency
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $target_date
 * @property string $status
 * @property-read Account|null $account
 */
final class Goal extends Model
{
    use BelongsToUser;

    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'account_id',
        'name',
        'target_minor',
        'target_currency',
        'start_date',
        'target_date',
        'status',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

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
