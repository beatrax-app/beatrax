<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * The single read/write path for `migration_source_map` (+ its sibling
 * `migration_import_baseline`) — the PERSISTENT per-entity dedup key D-08/
 * D-09/D-10 requires, and the third leg of Plan 07's 3-way merge (D-11/D-12).
 *
 * `resolve()` answers "has this source entity already been promoted?" — an
 * exact `source_external_id` match is tried first; when the entity carries no
 * stable source id (nullable `source_external_id`), the D-10 natural-key
 * fallback is tried instead, but ONLY among rows that themselves have no
 * `source_external_id` (a natural key is never consulted as a fallback for
 * an entity that DOES carry a stable id — a rename would then wrongly
 * resolve to the OLD natural key instead of surfacing as "field changed").
 *
 * `record()` upserts the map row (idempotent on the composite unique key when
 * `source_external_id` is present; a manual existence check substitutes for
 * the natural-key path because SQLite treats `NULL` as distinct in UNIQUE
 * indexes — a second insert with the same `NULL` source_external_id would
 * otherwise slip past the constraint entirely) and snapshots the supplied
 * `$baselineFields` into `migration_import_baseline` (D-11), one row per
 * field so Plan 07's 3-way merge stays per-field granular (D-13).
 *
 * Every query is a raw `DatabaseManager` query-builder call, explicitly
 * `user_id`-scoped (never a chained dynamic-Eloquent call — PHPStan L10
 * strict `staticMethod.dynamicCall`), matching the `EnvelopeWriter`/
 * `GoalProgressQuery` convention this codebase uses throughout.
 */
final class SourceMapWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Resolve an already-promoted source entity to its beatrax id, or null
     * when it has never been promoted for this user/source/entity-type.
     */
    public function resolve(
        User $user,
        string $sourceProduct,
        string $entityType,
        ?string $sourceExternalId,
        ?string $naturalKey = null,
    ): ?int {
        $connection = $this->db->connection();

        if ($sourceExternalId !== null) {
            $row = $connection->table('migration_source_map')
                ->where('user_id', $user->id)
                ->where('source_product', $sourceProduct)
                ->where('source_entity_type', $entityType)
                ->where('source_external_id', $sourceExternalId)
                ->first(['beatrax_id']);

            if ($row !== null) {
                return self::toInt($row->beatrax_id);
            }
        }

        if ($naturalKey !== null) {
            $row = $connection->table('migration_source_map')
                ->where('user_id', $user->id)
                ->where('source_product', $sourceProduct)
                ->where('source_entity_type', $entityType)
                ->whereNull('source_external_id')
                ->where('natural_key', $naturalKey)
                ->first(['beatrax_id']);

            if ($row !== null) {
                return self::toInt($row->beatrax_id);
            }
        }

        return null;
    }

    /**
     * Upsert the map row for a just-promoted (or just-confirmed-unchanged)
     * source entity, then snapshot `$baselineFields` (D-11) — one
     * `migration_import_baseline` row per field, upserted on
     * (migration_source_map_id, field_name) so a re-import advances the same
     * row rather than accumulating history.
     *
     * @param  array<string, string|int|float|bool|null>  $baselineFields
     */
    public function record(
        User $user,
        string $sourceProduct,
        string $entityType,
        ?string $sourceExternalId,
        ?string $naturalKey,
        string $beatraxEntityType,
        int $beatraxId,
        array $baselineFields = [],
    ): void {
        $connection = $this->db->connection();
        $now = $this->clock->now();

        $query = $connection->table('migration_source_map')
            ->where('user_id', $user->id)
            ->where('source_product', $sourceProduct)
            ->where('source_entity_type', $entityType);

        if ($sourceExternalId !== null) {
            $query->where('source_external_id', $sourceExternalId);
        } else {
            // NULL is distinct-from-itself in a SQLite UNIQUE index — the
            // natural_key equality check is what actually prevents a
            // duplicate map row for a stable-id-less entity.
            $query->whereNull('source_external_id')->where('natural_key', $naturalKey);
        }

        $existing = $query->first(['id']);

        if ($existing !== null) {
            $mapId = self::toInt($existing->id);
            $connection->table('migration_source_map')->where('id', $mapId)->update([
                'beatrax_entity_type' => $beatraxEntityType,
                'beatrax_id' => $beatraxId,
                'natural_key' => $naturalKey,
                'updated_at' => $now,
            ]);
        } else {
            $mapId = self::toInt($connection->table('migration_source_map')->insertGetId([
                'user_id' => $user->id,
                'source_product' => $sourceProduct,
                'source_entity_type' => $entityType,
                'source_external_id' => $sourceExternalId,
                'beatrax_entity_type' => $beatraxEntityType,
                'beatrax_id' => $beatraxId,
                'natural_key' => $naturalKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        foreach ($baselineFields as $field => $value) {
            $this->recordBaseline($user, $mapId, $field, $value, $now->toDateTimeString());
        }
    }

    /**
     * Upsert one (migration_source_map_id, field_name) baseline row.
     */
    private function recordBaseline(User $user, int $mapId, string $field, string|int|float|bool|null $value, string $importedAt): void
    {
        $connection = $this->db->connection();
        $stringValue = self::baselineValueToString($value);

        $existing = $connection->table('migration_import_baseline')
            ->where('migration_source_map_id', $mapId)
            ->where('field_name', $field)
            ->first(['id']);

        if ($existing !== null) {
            $connection->table('migration_import_baseline')
                ->where('id', self::toInt($existing->id))
                ->update([
                    'baseline_value' => $stringValue,
                    'imported_at' => $importedAt,
                ]);

            return;
        }

        $connection->table('migration_import_baseline')->insert([
            'user_id' => $user->id,
            'migration_source_map_id' => $mapId,
            'field_name' => $field,
            'baseline_value' => $stringValue,
            'imported_at' => $importedAt,
        ]);
    }

    private static function baselineValueToString(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
