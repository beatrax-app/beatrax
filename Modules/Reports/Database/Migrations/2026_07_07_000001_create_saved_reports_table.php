<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the saved_reports table — one row per user-saved report definition
 * (Req 9/10, D-02). `definition` carries the full report definition payload
 * (metric/dimension/period/filters/currencyMode/viz/compare) as JSON, mirroring
 * `rule_actions.payload`'s JSON-column precedent.
 *
 * `user_id` is nullable per the project's multi-user-ready convention (A4) —
 * unlike `envelope_settings`/`envelope_assignments`, there is no natural
 * UNIQUE(user_id, ...) constraint here that would require a non-null user_id
 * for upsert safety.
 *
 * `name` and `definition` are NOT NULL with NO DB-level default — the Sync
 * SavedReportsRegistryColumnsTest pins them as the genuinely required create
 * columns (Pitfall 5). `pinned` carries a DB-level `->default(false)`, which
 * intentionally drops it OUT of the NOT-NULL-without-default set — it must
 * NOT appear in MergeRulesRegistry's `_create_required` list even though it
 * is itself NOT NULL. `pin_order` is nullable (only set while pinned).
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('saved_reports', static function (Blueprint $table): void {
            $table->id();
            // nullable per multi-user-ready convention (A4) — no natural
            // UNIQUE constraint forces non-null here, unlike envelope_settings.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');                    // NOT NULL, no default — required create column
            $table->json('definition');                 // NOT NULL, no default — full ReportDefinition payload
            $table->boolean('pinned')->default(false);  // DB default -> NOT in _create_required (Pitfall 5)
            $table->unsignedTinyInteger('pin_order')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('saved_reports');
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
