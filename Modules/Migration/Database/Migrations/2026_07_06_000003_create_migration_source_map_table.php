<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates `migration_source_map` (PERSISTENT — survives a run's
 * confirm/discard, unlike the `migration_staging_*` scratch tables): the
 * per-entity deterministic dedup key D-08/D-09/D-10 requires. Every entity a
 * migration promotes to a real domain table (category, account, payee,
 * transaction, transfer_pair, budget_assignment) gets one row here mapping
 * `(user_id, source_product, source_entity_type, source_external_id)` to
 * `(beatrax_entity_type, beatrax_id)`, so a re-run of the identical export is
 * idempotent (Req 9) and a later re-import can relocate the row for 3-way
 * merge reconciliation (Req 10) even after a mutable field changes.
 *
 * `natural_key` (D-10) is the fallback dedup key for source entities lacking
 * a stable id (e.g. nYNAB payees/accounts, which carry only a display-name
 * string — see 13.5-RESEARCH.md's nYNAB findings). Natural-key-ONLY dedup
 * (no map row at all) was explicitly rejected: a renamed category's natural
 * key changes, so only this persistent id-keyed map lets a rename be
 * recognised as "same entity, field changed" rather than "new entity +
 * orphaned old one."
 *
 * This table IS registered in `Modules\Sync\Internal\Config\MergeRulesRegistry`
 * (Open Question 5, resolved: register both persistent Migration tables so a
 * second device cannot silently diverge and double-import) — the six
 * `migration_staging_*` tables and `migration_runs` are NOT registered.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('migration_source_map', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // 'ynab4' | 'nynab' | 'actual'
            $table->string('source_product');
            // 'category' | 'account' | 'payee' | 'transaction' | 'transfer_pair' | 'budget_assignment'
            $table->string('source_entity_type');
            // Nullable: D-10 natural-key fallback applies when the source has
            // no stable id at all (natural_key carries the identity instead).
            $table->string('source_external_id')->nullable();
            $table->string('beatrax_entity_type');
            $table->unsignedBigInteger('beatrax_id');
            // D-10 fallback dedup key (type + normalized name) for sources
            // lacking a stable source_external_id.
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

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
