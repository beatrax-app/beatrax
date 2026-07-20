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
 * @property string $counterparty_index_view
 * @property string $reports_index_view
 * @property array<array-key, mixed>|null $skipped_update_versions
 * @property array<array-key, mixed>|null $calendar_entries_accounts
 * @property array<array-key, mixed>|null $calendar_balance_accounts
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UserPreference extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'user_preferences';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'counterparty_index_view',
        'skipped_update_versions',
        'calendar_entries_accounts',
        'calendar_balance_accounts',
        'reports_index_view',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skipped_update_versions' => 'array',
            'calendar_entries_accounts' => 'array',
            'calendar_balance_accounts' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
