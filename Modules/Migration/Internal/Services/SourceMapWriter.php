<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class SourceMapWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

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
