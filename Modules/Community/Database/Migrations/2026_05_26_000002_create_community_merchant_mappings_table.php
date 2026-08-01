<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the community_merchant_mappings table — the bundled corpus of
 * crowd-sourced merchant identifications used by the import preview and
 * the `/community/mystery-merchants` page.
 *
 * Rows with `user_id IS NULL` are global corpus entries seeded from the
 * bundled `resources/corpus/merchant-mappings.yaml` file at install
 * time; rows with a non-null `user_id` are per-user overrides where the
 * user supplied their own friendly name for a pattern.
 *
 * The composite UNIQUE on (user_id, pattern) keeps a single corpus
 * entry per (user-or-global, pattern) tuple — a global row for
 * pattern X and a per-user override for the same pattern coexist
 * because their (user_id) tuples differ. The (generalized_pattern)
 * index supports the resolver's fuzzy-match scan across the
 * corpus.
 *
 * The `contributor` string identifies the source of each row
 * (`beatrax-bot` for bundled corpus rows, the user's own username
 * for per-user overrides) and is surfaced in the suggest-mapping
 * modal's provenance line.
 */
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

            // The composite UNIQUE keys per-user override rows on
            // (user_id, pattern). SQLite treats NULL values in a
            // composite UNIQUE as distinct so it does not block
            // multiple global rows with the same pattern; the partial
            // index below (CREATE UNIQUE INDEX ... WHERE user_id IS
            // NULL) enforces uniqueness for the global tier.
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
