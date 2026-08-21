<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('community_merchant_mappings', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('pattern');
            $table->string('generalized_pattern')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('region', 8)->nullable();
            $table->string('contributor', 64);
            $table->timestamps();

            // SQLite treats NULLs in a composite UNIQUE as distinct, so this
            // constrains per-user overrides only — the partial index below is
            // what keeps the global (user_id IS NULL) tier unique on pattern.
            $table->unique(['user_id', 'pattern']);
            $table->index(['generalized_pattern']);
        });

        $this->db()->connection($this->getConnection())->statement(
            'CREATE UNIQUE INDEX community_merchant_mappings_global_pattern_uq ON community_merchant_mappings(pattern) WHERE user_id IS NULL'
        );
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())
            ->statement('DROP INDEX IF EXISTS community_merchant_mappings_global_pattern_uq');

        $this->schema()->dropIfExists('community_merchant_mappings');
    }
};
