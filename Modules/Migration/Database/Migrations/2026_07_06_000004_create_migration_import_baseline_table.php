<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Third leg of the 3-way merge: the source value as of the last import,
        // so a re-import can compare source vs baseline vs local and pick skip /
        // apply / conflict. One row per (source map row, field) gives per-field
        // granularity; baseline_value stays untyped text to fit every field shape.
        $this->schema()->create('migration_import_baseline', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('migration_source_map_id')->constrained('migration_source_map')->cascadeOnDelete();
            $table->string('field_name');
            $table->text('baseline_value');
            $table->timestamp('imported_at');

            $table->index(['migration_source_map_id', 'field_name']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_import_baseline');
    }
};
