<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Persistent, unlike the migration_staging_* scratch, and registered in
        // Sync's MergeRulesRegistry so a second device cannot double-import.
        // Natural-key-only dedup was rejected: a rename orphans the old row.
        $this->schema()->create('migration_source_map', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // 'ynab4' | 'nynab' | 'actual'
            $table->string('source_product');
            // 'category' | 'account' | 'payee' | 'transaction' | 'transfer_pair' | 'budget_assignment'
            $table->string('source_entity_type');
            // Nullable: sources with no stable id are identified by natural_key.
            $table->string('source_external_id')->nullable();
            $table->string('beatrax_entity_type');
            $table->unsignedBigInteger('beatrax_id');
            // Fallback dedup key: entity type + normalized name.
            $table->string('natural_key')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'source_product', 'source_entity_type', 'source_external_id'],
                'migration_source_map_dedup_unique',
            );
            $table->index(['user_id', 'beatrax_entity_type', 'beatrax_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_source_map');
    }
};
