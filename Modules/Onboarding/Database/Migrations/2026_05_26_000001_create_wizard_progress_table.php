<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The (user_id, status) index serves ResumeStepResolver, which asks for
// this user's in_progress step on every /setup-wizard request.
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
