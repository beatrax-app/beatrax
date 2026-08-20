<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The only legal mutator of `status` is InboxScanStateMachine; a boundary test
// blocks every other write path under Modules/EmailScan/.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('inbox_scan_state', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('inboxes')->cascadeOnDelete();
            $table->string('folder', 64)->default('INBOX');
            $table->string('last_history_id', 64)->nullable();
            // Microsoft Graph delta-link URLs encode the full cursor state
            // and routinely exceed 255 chars — store as text.
            $table->text('last_delta_link')->nullable();
            $table->timestamp('last_scan_at')->nullable();
            $table->string('status', 32)->default('idle');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_attempts')->default(0);
            $table->timestamps();

            $table->unique(['inbox_id', 'folder']);
            $table->index(['user_id', 'status']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStatuses = "'idle','backfilling','scanning','rate_limited','needs_reauth','error'";

        $connection->statement(sprintf(
            "CREATE TRIGGER inbox_scan_state_status_check_insert BEFORE INSERT ON inbox_scan_state FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_scan_state.status value'); END",
            $allowedStatuses,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER inbox_scan_state_status_check_update BEFORE UPDATE OF status ON inbox_scan_state FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_scan_state.status value'); END",
            $allowedStatuses,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('inbox_scan_state');
    }
};
