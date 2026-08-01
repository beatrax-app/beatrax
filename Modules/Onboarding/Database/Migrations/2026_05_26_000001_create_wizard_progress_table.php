<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the wizard_progress table — the per-user state machine that
 * tracks each user's progression through the first-run setup wizard.
 *
 * One row per (user_id, step_key). The `status` column is one of
 * `pending`, `in_progress`, `done`, `skipped`; the allowed set is
 * enforced via paired BEFORE INSERT / BEFORE UPDATE OF status triggers
 * so a typo in the application layer fails loud at the database
 * boundary rather than landing a silently-broken state.
 *
 * The `data` JSON column carries per-step opaque payload (e.g. the
 * connector chosen, the OAuth provider name, the uploaded filename)
 * so the wizard can resume mid-flow after an app relaunch without
 * re-prompting. The `completed_at` timestamp records the moment the
 * step transitions to `done` or `skipped`.
 *
 * The composite UNIQUE on (user_id, step_key) blocks duplicate-step
 * rows for the same user. The (user_id, status) index is the
 * ResumeStepResolver hot path: it asks "which step is currently
 * in_progress for this user?" on every `/setup` page request.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('wizard_progress', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('step_key', 64);
            $table->string('status', 16);
            $table->json('data')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'step_key']);
            $table->index(['user_id', 'status']);
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(<<<'SQL'
            CREATE TRIGGER wizard_progress_status_check_insert BEFORE INSERT ON wizard_progress FOR EACH ROW
            WHEN NEW.status NOT IN ('pending','in_progress','done','skipped')
            BEGIN SELECT RAISE(ABORT, 'Invalid wizard_progress.status value'); END
        SQL);
        $connection->statement(<<<'SQL'
            CREATE TRIGGER wizard_progress_status_check_update BEFORE UPDATE OF status ON wizard_progress FOR EACH ROW
            WHEN NEW.status NOT IN ('pending','in_progress','done','skipped')
            BEGIN SELECT RAISE(ABORT, 'Invalid wizard_progress.status value'); END
        SQL);
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS wizard_progress_status_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS wizard_progress_status_check_update');

        $this->schema()->dropIfExists('wizard_progress');
    }
};
