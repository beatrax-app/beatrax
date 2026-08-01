<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the inbox_scan_state table — the resume cursor + lifecycle
 * row for the per-inbox background scanner.
 *
 * One row per (inbox_id, folder) pair. The default folder is 'INBOX';
 * multi-folder support is a forward-compatible extension that the
 * unique key already accommodates. `last_history_id` carries the
 * Gmail-style history cursor; `last_delta_link` carries the Microsoft
 * Graph delta-link URL (which can exceed the 255-char default, hence
 * the text column). The `status` column drives the per-inbox health
 * badge in the UI; an enum-shaped string enforced via paired BEFORE
 * INSERT / BEFORE UPDATE triggers.
 *
 * The only legal mutator of the `status` column is the
 * InboxScanStateMachine inside the EmailScan module; a boundary test
 * blocks every other write path under Modules/EmailScan/.
 */
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
