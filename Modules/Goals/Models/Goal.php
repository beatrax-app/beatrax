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
 * A savings goal with an optional linked account for contribution tracking.
 *
 * Contributions are counted as credits (type IN transfer_in, income) posted
 * on/after `start_date` on the linked `account_id`. When `account_id` is null
 * the goal is "unlinked" — progress shows 0/target with no projection.
 *
 * Money follows the project-wide signed BIGINT minor-units convention:
 * `target_minor` is always positive (a goal amount, not a signed flow).
 *
 * `status` lifecycle: active → completed (explicit mark) | archived (hidden).
 * The `BelongsToUser` trait adds a global UserScope so resolving a foreign
 * `goal_id` (client-supplied) safely returns nothing.
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

    /**
     * Override factory resolution — Laravel's default resolver derives
     * `Database\Factories\<ModelName>Factory` which is not where module
     * factories live. Pointing directly to GoalFactory ensures
     * `Goal::factory()` resolves correctly in tests.
     *
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return GoalFactory::new();
    }
}
