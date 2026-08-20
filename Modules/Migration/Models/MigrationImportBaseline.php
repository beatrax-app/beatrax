<?php

declare(strict_types=1);

namespace Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $migration_source_map_id
 * @property string $field_name
 * @property string $baseline_value
 * @property CarbonImmutable $imported_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MigrationImportBaseline extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'migration_source_map_id',
        'field_name',
        'baseline_value',
        'imported_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'imported_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MigrationSourceMap, $this>
     */
    public function sourceMap(): BelongsTo
    {
        return $this->belongsTo(MigrationSourceMap::class);
    }
}
