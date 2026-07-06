<?php

declare(strict_types=1);

namespace Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Persistent per-entity dedup key (D-08/D-09/D-10): maps
 * `(user_id, source_product, source_entity_type, source_external_id)` to
 * `(beatrax_entity_type, beatrax_id)`. Survives a migration run's
 * confirm/discard — unlike the `migration_staging_*` scratch tables, this
 * row is the durable record a later re-import's 3-way merge reconciliation
 * (Req 10) relocates.
 *
 * `natural_key` is the D-10 fallback identity (type + normalized name) for
 * source entities with no stable id (e.g. nYNAB accounts/payees).
 *
 * Registered in `Modules\Sync\Internal\Config\MergeRulesRegistry` (Open
 * Question 5) as a persistent multi-device sync surface.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $source_product
 * @property string $source_entity_type
 * @property string|null $source_external_id
 * @property string $beatrax_entity_type
 * @property int $beatrax_id
 * @property string|null $natural_key
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MigrationSourceMap extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'source_product',
        'source_entity_type',
        'source_external_id',
        'beatrax_entity_type',
        'beatrax_id',
        'natural_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'beatrax_id' => 'integer',
        ];
    }
}
