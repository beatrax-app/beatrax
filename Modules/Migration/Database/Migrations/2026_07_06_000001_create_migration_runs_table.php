<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates `migration_runs` — one row per YNAB4/nYNAB/Actual import attempt
 * (Req 1/11). This is the parent scope key every `migration_staging_*` table
 * hangs off of via `migration_run_id` (D-06/D-07): a run's staging rows are
 * scratch, safe to truncate wholesale on discard/abandon without touching any
 * domain table.
 *
 * `status` lifecycle: 'parsed' (upload complete, staging populated) ->
 * 'confirmed' (promoted to domain tables via the public writers) OR
 * 'discarded' (user abandoned, staging truncated) OR 'needs_attention'
 * (reconciliation on a re-import found unresolved conflicts — Req 10/D-13).
 * `confirmed_at` stays null until the confirm action flips status.
 *
 * `user_id` is nullable per the project-wide multi-user-readiness convention
 * (ADR-0008), even though a migration run is inherently single-user in
 * practice.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('migration_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // 'ynab4' | 'nynab' | 'actual'
            $table->string('source_product');
            // 'parsed' | 'confirmed' | 'needs_attention' | 'discarded'
            $table->string('status')->default('parsed');
            $table->string('original_filename');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_runs');
    }
};
