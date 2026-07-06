<?php

declare(strict_types=1);

namespace Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Persistent third leg of the 3-way merge (D-11/D-12): per mapped entity +
 * field, the source-provided value at the time it was last imported/applied.
 * A later re-import compares `{new source}` vs `{this baseline}` vs
 * `{current beatrax value}` to decide skip / apply / conflict (Req 10).
 *
 * One row per (migration_source_map_id, field_name) so conflicts stay
 * per-item AND per-field granular (D-13).
 *
 * Registered in `MergeRulesRegistry` alongside `migration_source_map` (Open
 * Question 5, resolved: register both persistent tables).
 *
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
