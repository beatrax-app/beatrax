<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Conflicts live here under item_type = 'conflict' rather than in a table
        // of their own, so preview and confirm keep one read path. Nullable
        // throughout, since only conflict rows populate them.
        $this->schema()->table('migration_staging_unmapped_items', static function (Blueprint $table): void {
            $table->string('entity_type')->nullable()->after('source_external_id');
            $table->string('field_name')->nullable()->after('entity_type');
            // Stringified round-trippably, never display-formatted.
            $table->text('local_value')->nullable()->after('field_name');
            $table->text('source_value')->nullable()->after('local_value');
            $table->text('baseline_value')->nullable()->after('source_value');
            $table->char('currency', 3)->nullable()->after('baseline_value');
            // NULL is equivalent to 'keep_local': the toggle is untouched.
            $table->string('resolution')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        $this->schema()->table('migration_staging_unmapped_items', static function (Blueprint $table): void {
            $table->dropColumn(['entity_type', 'field_name', 'local_value', 'source_value', 'baseline_value', 'currency', 'resolution']);
        });
    }
};
