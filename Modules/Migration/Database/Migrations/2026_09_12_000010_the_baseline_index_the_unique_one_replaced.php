<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The unique index added beside it keys on the same two columns in the same
// order, so it answers every lookup this one did. Kept, it was a second B-tree
// maintained per baseline row — one row per mapped row and field — and read by
// nothing.
/**
 * @link ../../../../.docs/architecture/a-rebuilt-table-loses-a-partial-index.md#the-guard
 */
return new class extends ModuleMigration
{
    private const string INDEX = 'migration_import_baseline_migration_source_map_id_field_name_index';

    public function up(): void
    {
        if (! $this->schema()->hasTable('migration_import_baseline')) {
            return;
        }

        $this->db()->connection($this->getConnection())
            ->statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable('migration_import_baseline')) {
            return;
        }

        $this->schema()->table('migration_import_baseline', static function (Blueprint $table): void {
            $table->index(['migration_source_map_id', 'field_name'], self::INDEX);
        });
    }
};
