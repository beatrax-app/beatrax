<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\ValueObjects\SourceMapKey;

final class SourceMapWriter
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function resolve(User $user, SourceMapKey $key): ?int
    {
        $connection = $this->db->connection();

        if ($key->sourceExternalId !== null) {
            $row = $connection->table('migration_source_map')
                ->where('user_id', $user->id)
                ->where('source_product', $key->sourceProduct)
                ->where('source_entity_type', $key->entityType)
                ->where('source_external_id', $key->sourceExternalId)
                ->first(['beatrax_id']);

            if ($row !== null) {
                return self::toInt($row->beatrax_id);
            }
        }

        if ($key->naturalKey !== null) {
            $row = $connection->table('migration_source_map')
                ->where('user_id', $user->id)
                ->where('source_product', $key->sourceProduct)
                ->where('source_entity_type', $key->entityType)
                ->whereNull('source_external_id')
                ->where('natural_key', $key->naturalKey)
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
        SourceMapKey $key,
        string $beatraxEntityType,
        int $beatraxId,
        array $baselineFields = [],
    ): void {
        $connection = $this->db->connection();
        $now = $this->clock->now();

        $query = $connection->table('migration_source_map')
            ->where('user_id', $user->id)
            ->where('source_product', $key->sourceProduct)
            ->where('source_entity_type', $key->entityType);

        if ($key->sourceExternalId !== null) {
            $query->where('source_external_id', $key->sourceExternalId);
        } else {
            // NULL is distinct from itself in a SQLite UNIQUE index, so the
            // natural_key equality is what prevents a duplicate map row here.
            $query->whereNull('source_external_id')->where('natural_key', $key->naturalKey);
        }

        $existing = $query->first(['id']);

        if ($existing !== null) {
            $mapId = self::toInt($existing->id);
            $connection->table('migration_source_map')->where('id', $mapId)->update([
                'beatrax_entity_type' => $beatraxEntityType,
                'beatrax_id' => $beatraxId,
                'natural_key' => $key->naturalKey,
                'updated_at' => $now,
            ]);
        } else {
            $mapId = self::toInt($connection->table('migration_source_map')->insertGetId([
                'user_id' => $user->id,
                'source_product' => $key->sourceProduct,
                'source_entity_type' => $key->entityType,
                'source_external_id' => $key->sourceExternalId,
                'beatrax_entity_type' => $beatraxEntityType,
                'beatrax_id' => $beatraxId,
                'natural_key' => $key->naturalKey,
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
}
