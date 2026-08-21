<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('chain_resolution_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Stamped after the queue push, so the wizard can reserve the
            // row before the dispatch returns.
            $table->string('job_uuid', 36)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 16);
            $table->integer('linked_count')->default(0);
            // Redact transaction ids before writing here: exception class
            // plus one sanitised line only.
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStatuses = "'pending','running','complete','failed'";

        $connection->statement(sprintf(
            "CREATE TRIGGER chain_resolution_runs_status_check_insert BEFORE INSERT ON chain_resolution_runs FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_resolution_runs.status value'); END",
            $allowedStatuses,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_resolution_runs_status_check_update BEFORE UPDATE OF status ON chain_resolution_runs FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_resolution_runs.status value'); END",
            $allowedStatuses,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('chain_resolution_runs');
    }
};
