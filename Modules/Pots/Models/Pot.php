<?php

declare(strict_types=1);

namespace Modules\Pots\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Account;
use Modules\Pots\Database\Factories\PotFactory;

/**
 * @link ../../../.docs/features/pots/architecture.md
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $account_id
 * @property int|null $goal_id
 * @property int|null $category_id
 * @property string $name
 * @property string $currency
 * @property string $status
 * @property-read Account|null $account
 */
final class Pot extends Model
{
    use BelongsToUser;

    /** @use HasFactory<PotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'account_id',
        'goal_id',
        'category_id',
        'name',
        'currency',
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
            'account_id' => 'integer',
            'goal_id' => 'integer',
            'category_id' => 'integer',
        ];
    }

    // Laravel's default resolver derives Database\Factories\<ModelName>Factory,
    // which is not where module factories live — point directly at PotFactory.
    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return PotFactory::new();
    }
}
