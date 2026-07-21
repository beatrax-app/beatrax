<?php

declare(strict_types=1);

namespace Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @link ../../../.docs/features/migration/architecture.md
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $source_product
 * @property string $status
 * @property string $original_filename
 * @property CarbonImmutable|null $confirmed_at
 * @property int $categories_count
 * @property int $accounts_count
 * @property int $transactions_inserted_count
 * @property int $transactions_skipped_count
 * @property int $splits_count
 * @property int $transfers_paired_count
 * @property int $counterparties_resolved_count
 * @property int $goals_created_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MigrationRun extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'source_product',
        'status',
        'original_filename',
        'confirmed_at',
        'categories_count',
        'accounts_count',
        'transactions_inserted_count',
        'transactions_skipped_count',
        'splits_count',
        'transfers_paired_count',
        'counterparties_resolved_count',
        'goals_created_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
