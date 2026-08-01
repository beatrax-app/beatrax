<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the file_imports table — the lightweight DB index over the
 * raw .eml blobs that arrived via file drop rather than the
 * EmailScan API fetcher.
 *
 * Mirrors the inbox_messages table shape so the matcher consumer can
 * unify both tables behind a single MatcherInputDto stream:
 *
 *   - One row per dropped .eml or per message inside a .mbox archive.
 *   - The pair (user_id, provider_message_id) is UNIQUE so re-dropping
 *     the same file is a no-op via insertOrIgnore.
 *   - Headerless messages get a synthetic provider_message_id = the
 *     sha256 of the raw bytes; same bytes therefore round-trip to the
 *     same idempotency key.
 *   - The deterministic eml_path is derived from sha256(bytes); the
 *     user-supplied source_filename is stored verbatim for audit only,
 *     never used to construct the on-disk path.
 *   - The `status` enum follows the same lifecycle as inbox_messages
 *     (fetched → parsed | skipped | unmatched), enforced via paired
 *     BEFORE INSERT / BEFORE UPDATE triggers. A second paired trigger
 *     validates the source_kind enum (eml | mbox).
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('file_imports', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('source_kind', 16);
            $table->string('source_filename', 512);
            $table->string('provider_message_id', 128);
            $table->timestamp('internal_date');
            $table->string('sender_email', 320);
            $table->string('sender_name', 320)->nullable();
            $table->string('subject', 998)->nullable();
            $table->string('eml_path', 1024);
            $table->string('status', 16)->default('fetched');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['user_id', 'provider_message_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'internal_date']);
        });

        $connection = $this->db()->connection($this->getConnection());

        $allowedStatuses = "'fetched','parsed','skipped','unmatched'";
        $connection->statement(sprintf(
            "CREATE TRIGGER file_imports_status_check_insert BEFORE INSERT ON file_imports FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.status value'); END",
            $allowedStatuses,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER file_imports_status_check_update BEFORE UPDATE OF status ON file_imports FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.status value'); END",
            $allowedStatuses,
        ));

        $allowedKinds = "'eml','mbox'";
        $connection->statement(sprintf(
            "CREATE TRIGGER file_imports_source_kind_check_insert BEFORE INSERT ON file_imports FOR EACH ROW
             WHEN NEW.source_kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.source_kind value'); END",
            $allowedKinds,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER file_imports_source_kind_check_update BEFORE UPDATE OF source_kind ON file_imports FOR EACH ROW
             WHEN NEW.source_kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.source_kind value'); END",
            $allowedKinds,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS file_imports_source_kind_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS file_imports_source_kind_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS file_imports_status_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS file_imports_status_check_insert');

        $this->schema()->dropIfExists('file_imports');
    }
};
