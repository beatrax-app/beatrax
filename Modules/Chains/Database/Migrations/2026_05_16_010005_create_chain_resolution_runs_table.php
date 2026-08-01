<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the chain_resolution_runs table — an audit row per chain
 * resolver invocation that the import wizard polls for "Resolving
 * chains…" status updates and that surfaces failed-job toasts on the
 * dashboard.
 *
 * One row per `ResolveChainLinksJob` dispatch (the future Wave 2 job):
 *
 *   - `user_id` is non-nullable (every row maps to exactly one user;
 *     the BelongsToUser invariant still applies but the safer non-
 *     nullable shape mirrors `import_runs`)
 *   - `job_uuid` is set after queue push so the wizard can reserve the
 *     row BEFORE the dispatch returns
 *   - `started_at` / `completed_at` are stamped by the worker as it
 *     transitions through the status lifecycle
 *   - `status` lifecycle: `pending` (row reserved) → `running` (worker
 *     began handle()) → `complete` (success) / `failed` (final retry
 *     exhausted)
 *   - `linked_count` records how many chain_links the resolver wrote
 *     in this run (for the wizard's "Imported X · linked Y" summary)
 *   - `last_error` is the truncated exception message on failure;
 *     resolver code is responsible for redacting any transaction ids
 *     before they land here (only the exception class + first
 *     sanitised line)
 *
 * The (user_id, created_at) descending index is the wizard's
 * "latest run for this user" hot path.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('chain_resolution_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('job_uuid', 36)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 16);
            $table->integer('linked_count')->default(0);
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
